<?php

namespace App\Http\Controllers\Api\V1\Categories;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\AuditService;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected AuditService $audit,
        protected BusinessContext $context
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        $categories = $this->scopedQuery($request)->get();

        // The tree is assembled in PHP from one flat query: unlimited depth
        // must never cost one query per node.
        return $this->success(CategoryResource::collection($this->buildTree($categories)), 'Categories tree');
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', Category::class);
        $this->ensureCompanyAccess($request->company_id);

        $attributes = $request->validated();
        $attributes['level'] = $this->levelOf($attributes['parent_id'] ?? null, $request->company_id);

        $category = Category::create($attributes);

        $this->audit->record('category.create', 'category', $category->id, null, $request->validated(), $category->company_id);

        return $this->success(new CategoryResource($category->load('parent')), 'Category created', 201);
    }

    public function show(Category $category): JsonResponse
    {
        $this->authorize('view', $category);

        return $this->success(new CategoryResource($category->load(['parent', 'children'])));
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $this->authorize('update', $category);

        // exists() rather than filled(): an explicit null reparents to the root.
        $newParentId = $request->exists('parent_id') ? $request->input('parent_id') : $category->parent_id;

        if ($newParentId !== null && $newParentId !== $category->parent_id) {
            $newParent = Category::query()
                ->where('company_id', $category->company_id)
                ->whereKey($newParentId)
                ->first();

            // Reparenting under a descendant would close a loop, so the move
            // is rejected as a data problem rather than an authorisation one.
            if ($newParent?->isDescendantOf($category)) {
                throw ValidationException::withMessages([
                    'parent_id' => 'A category cannot be moved under one of its own descendants.',
                ]);
            }
        }

        $old = $category->only(array_keys($request->validated()));

        $category->update($request->validated());

        // A move shifts the level of every descendant, so the denormalised
        // levels of the whole company tree are recomputed in one pass.
        $this->rebuildLevels($category->company_id);

        $this->audit->record('category.update', 'category', $category->id, $old, $category->fresh()->only(array_keys($old)), $category->company_id);

        return $this->success(new CategoryResource($category->fresh()->load(['parent', 'children'])));
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->authorize('delete', $category);

        $category->delete();

        $this->audit->record('category.delete', 'category', $category->id, $category->toArray(), null, $category->company_id);

        return $this->success(null, 'Category deleted');
    }

    private function scopedQuery(Request $request)
    {
        $user = $request->user();
        $companyId = $request->integer('company_id') ?: $this->context->companyId();

        return Category::query()
            ->visibleTo($user)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->search, fn ($q, $search) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            }))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->has('parent_id'), function ($q) use ($request) {
                $parentId = $request->input('parent_id');

                if ($parentId === null || $parentId === '') {
                    $q->whereNull('parent_id');
                } else {
                    $q->where('parent_id', (int) $parentId);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * Nest a flat category list in place, keyed by parent.
     *
     * @param  EloquentCollection<int, Category>  $categories
     * @return Collection<int, Category> the roots
     */
    private function buildTree(EloquentCollection $categories): Collection
    {
        $byParent = $categories->groupBy('parent_id');

        $categories->each(
            fn (Category $category) => $category->setRelation('children', $byParent->get($category->id, collect()))
        );

        return $byParent->get(null, collect());
    }

    /**
     * Level of a category under the given parent: roots sit at level 0.
     */
    private function levelOf(?int $parentId, int $companyId): int
    {
        if ($parentId === null) {
            return 0;
        }

        $parent = Category::query()
            ->where('company_id', $companyId)
            ->whereKey($parentId)
            ->first();

        return $parent ? $parent->level + 1 : 0;
    }

    /**
     * Recompute the denormalised level of every category in a company tree.
     *
     * One query to read the adjacency list, then a breadth-first walk from the
     * roots; only the rows whose level actually changed are written back.
     */
    private function rebuildLevels(int $companyId): void
    {
        $rows = Category::query()
            ->where('company_id', $companyId)
            ->get(['id', 'parent_id', 'level']);

        $byParent = $rows->groupBy('parent_id');
        $changed = [];
        $queue = [[null, -1]];

        while ($queue !== []) {
            [$parentId, $parentLevel] = array_shift($queue);

            foreach ($byParent->get($parentId, collect()) as $row) {
                $level = $parentLevel + 1;

                if ($row->level !== $level) {
                    $changed[$level][] = $row->id;
                }

                $queue[] = [$row->id, $level];
            }
        }

        foreach ($changed as $level => $ids) {
            Category::query()->whereIntegerInRaw('id', $ids)->update(['level' => $level]);
        }
    }

    private function ensureCompanyAccess(int $companyId): void
    {
        $user = request()->user();

        if (! $user->companies()->where('companies.id', $companyId)->exists()) {
            throw new AuthorizationException('You do not have access to this company.');
        }
    }
}
