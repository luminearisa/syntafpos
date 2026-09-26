import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';

export default function NotFoundPage() {
  return (
    <main className="relative flex min-h-dvh items-center justify-center overflow-hidden bg-background px-5 py-10 text-center">
      <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top,rgba(49,88,201,0.09),transparent_50%)]" />
      <div className="relative w-full max-w-lg">
        <span className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-primary-light to-primary text-white shadow-md">
          <ion-icon name="calculator-outline" class="text-2xl" aria-hidden="true" />
        </span>
        <p className="mt-8 text-[11px] font-bold tracking-[0.18em] text-primary uppercase">Error 404</p>
        <h1 className="mt-2 text-3xl font-bold tracking-[-0.04em] text-text sm:text-4xl">
          This page went missing.
        </h1>
        <p className="mx-auto mt-3 max-w-sm text-sm leading-relaxed text-text-muted">
          The page may have moved, or the link may be out of date. Let&apos;s get you back to your workspace.
        </p>
        <Link to="/dashboard" className="mt-6 inline-flex">
          <Button variant="primary" icon="arrow-back-outline">
            Back to dashboard
          </Button>
        </Link>
        <p className="mt-8 text-xs font-semibold tracking-wide text-text-muted">SyntafPOS · Retail operations</p>
      </div>
    </main>
  );
}
