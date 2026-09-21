import { useEffect, useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '@/stores/auth-store';
import { Card, CardBody, CardHeader } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { LoadingState, PageHeader } from '@/components/ui/state';
import { cn, formatDate, initials } from '@/utils/format';
import type { UserStatus } from '@/types';

const STATUS_STYLES: Record<UserStatus, string> = {
  active: 'bg-success-soft text-success',
  suspended: 'bg-warning-soft text-warning',
  inactive: 'bg-surface-alt text-text-muted',
};

const PROFILE_ERROR = 'Unable to load your profile. Please try again.';

export default function ProfilePage(): ReactNode {
  const user = useAuthStore((state) => state.user);
  const fetchMe = useAuthStore((state) => state.fetchMe);
  const navigate = useNavigate();
  const [error, setError] = useState<string | null>(null);
  const [isFetching, setIsFetching] = useState(false);

  useEffect(() => {
    if (user) {
      return;
    }

    let active = true;
    setError(null);
    setIsFetching(true);

    fetchMe().catch(() => {
      if (active) {
        setError(PROFILE_ERROR);
      }
    }).finally(() => {
      if (active) {
        setIsFetching(false);
      }
    });

    return () => {
      active = false;
    };
  }, [user, fetchMe]);

  if (!user) {
    if (error) {
      return (
        <div className="flex flex-col gap-4">
          <PageHeader title="My Profile" />
          <Card>
            <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
              <span className="flex h-12 w-12 items-center justify-center rounded-full bg-danger-soft text-danger">
                <ion-icon name="cloud-offline-outline" class="text-2xl" aria-hidden="true" />
              </span>
              <h3 className="text-sm font-semibold text-text">{error}</h3>
              <Button
                variant="outline"
                size="sm"
                icon="refresh"
                loading={isFetching}
                onClick={() => {
                  setError(null);
                  setIsFetching(true);
                  fetchMe()
                    .catch(() => setError(PROFILE_ERROR))
                    .finally(() => setIsFetching(false));
                }}
              >
                Try again
              </Button>
            </div>
          </Card>
        </div>
      );
    }

    return (
      <div className="flex flex-col gap-4">
        <PageHeader title="My Profile" />
        <Card>
          <LoadingState label="Loading profile..." />
        </Card>
      </div>
    );
  }

  const hasBusinessAccess =
    (user.companies?.length ?? 0) > 0 ||
    (user.branches?.length ?? 0) > 0 ||
    (user.warehouses?.length ?? 0) > 0 ||
    (user.registers?.length ?? 0) > 0;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="My Profile"
        description="Your account details and business access."
        actions={
          <Button
            variant="outline"
            icon="lock-closed-outline"
            onClick={() => navigate('/profile/password')}
          >
            Change password
          </Button>
        }
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-1">
          <CardBody className="flex flex-col items-center gap-3 p-6 text-center">
            <span className="flex h-16 w-16 items-center justify-center rounded-full bg-primary-soft text-xl font-semibold text-primary">
              {initials(user.name)}
            </span>

            <div>
              <h2 className="text-sm font-semibold text-text">{user.name}</h2>
              <p className="mt-0.5 text-xs text-text-muted">{user.email}</p>
            </div>

            <span
              className={cn(
                'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium',
                STATUS_STYLES[user.status]
              )}
            >
              <span className="h-1.5 w-1.5 rounded-full bg-current" />
              {user.status}
            </span>

            {user.roles && user.roles.length > 0 && (
              <div className="mt-1 flex flex-wrap justify-center gap-1.5">
                {user.roles.map((role) => (
                  <span
                    key={role.id}
                    className="inline-flex items-center rounded-md bg-primary-soft px-2 py-0.5 text-xs font-medium text-primary"
                    title={role.description ?? role.name}
                  >
                    {role.display_name}
                  </span>
                ))}
              </div>
            )}

            <div className="mt-2 flex w-full items-center justify-center gap-1.5 text-xs text-text-subtle">
              <ion-icon name="mail-outline" aria-hidden="true" />
              {user.email_verified_at ? (
                <span className="text-success">Email verified</span>
              ) : (
                <span>Email not verified</span>
              )}
            </div>
          </CardBody>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader title="Account information" />
          <CardBody>
            <dl className="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
              <Detail label="Full name" value={user.name} />
              <Detail label="Email address" value={user.email} />
              <Detail label="Phone number" value={user.phone} />
              <Detail label="Account status" value={user.status} />
              <Detail label="Last login" value={formatDate(user.last_login_at, true)} />
              <Detail label="Member since" value={formatDate(user.created_at)} />
            </dl>
          </CardBody>
        </Card>
      </div>

      <Card>
        <CardHeader
          title="Business access"
          description="The companies, branches, warehouses and registers you can reach."
        />
        <CardBody>
          {hasBusinessAccess ? (
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <AccessList
                title="Companies"
                icon="business-outline"
                items={user.companies?.map((company) => ({
                  id: company.id,
                  name: company.name,
                  code: company.code,
                }))}
              />
              <AccessList
                title="Branches"
                icon="storefront-outline"
                items={user.branches?.map((branch) => ({
                  id: branch.id,
                  name: branch.name,
                  code: branch.code,
                }))}
              />
              <AccessList
                title="Warehouses"
                icon="cube-outline"
                items={user.warehouses?.map((warehouse) => ({
                  id: warehouse.id,
                  name: warehouse.name,
                  code: warehouse.code,
                }))}
              />
              <AccessList
                title="Registers"
                icon="cash-outline"
                items={user.registers?.map((register) => ({
                  id: register.id,
                  name: register.name,
                  code: register.code,
                }))}
              />
            </div>
          ) : (
            <div className="flex flex-col items-center gap-2 px-6 py-8 text-center">
              <span className="flex h-10 w-10 items-center justify-center rounded-full bg-surface-alt text-text-subtle">
                <ion-icon name="business-outline" class="text-xl" aria-hidden="true" />
              </span>
              <h3 className="text-sm font-medium text-text">No business access yet</h3>
              <p className="max-w-sm text-xs text-text-muted">
                An administrator needs to assign you to at least one company before you can
                start transacting.
              </p>
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  );
}

function Detail({ label, value }: { label: string; value: string | null }): ReactNode {
  return (
    <div>
      <dt className="text-xs font-medium text-text-subtle">{label}</dt>
      <dd className="mt-0.5 break-words text-sm text-text">{value || '-'}</dd>
    </div>
  );
}

interface AccessEntry {
  id: number;
  name: string;
  code: string;
}

function AccessList({
  title,
  icon,
  items,
}: {
  title: string;
  icon: string;
  items?: AccessEntry[];
}): ReactNode {
  return (
    <div className="rounded-md border border-border bg-surface-alt p-3">
      <div className="mb-2.5 flex items-center gap-1.5">
        <ion-icon name={icon} class="text-base text-text-muted" aria-hidden="true" />
        <h4 className="text-xs font-semibold text-text">{title}</h4>
        <span className="ml-auto rounded-full bg-surface px-1.5 text-[11px] font-medium text-text-muted">
          {items?.length ?? 0}
        </span>
      </div>

      {items && items.length > 0 ? (
        <ul className="flex flex-col gap-1.5">
          {items.map((item) => (
            <li key={item.id} className="flex items-center gap-2 text-xs">
              <span className="truncate text-text">{item.name}</span>
              <span className="ml-auto shrink-0 rounded bg-surface px-1.5 py-0.5 font-mono text-[11px] text-text-muted">
                {item.code}
              </span>
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-xs text-text-subtle">None assigned.</p>
      )}
    </div>
  );
}
