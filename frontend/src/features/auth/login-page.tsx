import { useState, type FormEvent, type ReactNode } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuthStore } from '@/stores/auth-store';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardBody } from '@/components/ui/card';

const VALUE_POINTS: { icon: string; title: string; description: string }[] = [
  {
    icon: 'business-outline',
    title: 'Multi-company ready',
    description: 'Companies, branches, warehouses and registers in one place.',
  },
  {
    icon: 'analytics-outline',
    title: 'Real-time insight',
    description: 'Live dashboards keep every location accountable.',
  },
  {
    icon: 'shield-checkmark-outline',
    title: 'Role-based access',
    description: 'Granular permissions protect every transaction.',
  },
];

export default function LoginPage(): ReactNode {
  const navigate = useNavigate();
  const location = useLocation();
  const login = useAuthStore((state) => state.login);
  const isLoading = useAuthStore((state) => state.isLoading);

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(true);
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const from = readRedirectFrom(location.state);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();
    setError(null);

    try {
      await login(email.trim(), password);
      navigate(from, { replace: true });
    } catch (submitError) {
      setError(
        submitError instanceof Error ? submitError.message : 'Login failed. Please try again.'
      );
    }
  };

  return (
    <div className="flex min-h-screen bg-background">
      <BrandPanel />

      <div className="flex flex-1 items-center justify-center px-4 py-10 sm:px-6">
        <div className="w-full max-w-sm">
          <div className="mb-6 text-center">
            <h1 className="text-xl font-semibold text-text">Welcome back</h1>
            <p className="mt-1 text-sm text-text-muted">
              Sign in to your Ultimate POS workspace.
            </p>
          </div>

          <Card>
            <CardBody className="p-5">
              {error && (
                <div
                  role="alert"
                  className="mb-4 flex items-start gap-2 rounded-md bg-danger-soft px-3 py-2.5 text-sm text-danger"
                >
                  <ion-icon
                    name="alert-circle-outline"
                    class="mt-0.5 shrink-0"
                    aria-hidden="true"
                  />
                  <span>{error}</span>
                </div>
              )}

              <form className="flex flex-col gap-4" onSubmit={handleSubmit} noValidate>
                <Input
                  name="email"
                  type="email"
                  label="Email address"
                  placeholder="you@company.com"
                  icon="mail-outline"
                  autoComplete="email"
                  autoFocus
                  required
                  value={email}
                  onChange={(event) => setEmail(event.target.value)}
                />

                <div className="relative">
                  <Input
                    name="password"
                    type={showPassword ? 'text' : 'password'}
                    label="Password"
                    placeholder="Enter your password"
                    icon="lock-closed-outline"
                    autoComplete="current-password"
                    required
                    className="pr-9"
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword((value) => !value)}
                    className="absolute top-[1.375rem] right-2.5 flex h-6 w-6 items-center justify-center rounded text-text-subtle hover:text-text"
                    aria-label={showPassword ? 'Hide password' : 'Show password'}
                    aria-pressed={showPassword}
                  >
                    <ion-icon
                      name={showPassword ? 'eye-off-outline' : 'eye-outline'}
                      aria-hidden="true"
                    />
                  </button>
                </div>

                <div className="flex items-center justify-between">
                  <label className="flex cursor-pointer items-center gap-2 text-xs text-text-muted">
                    <input
                      type="checkbox"
                      className="h-4 w-4 rounded border-border text-primary focus:ring-primary"
                      checked={remember}
                      onChange={(event) => setRemember(event.target.checked)}
                    />
                    Remember me
                  </label>

                  <Link
                    to="/forgot-password"
                    className="text-xs font-medium text-primary hover:text-primary-dark"
                  >
                    Forgot password?
                  </Link>
                </div>

                <Button type="submit" size="lg" loading={isLoading} className="w-full">
                  Sign in
                </Button>
              </form>

              <div className="mt-5 rounded-md border border-dashed border-border bg-surface-alt px-3 py-2.5">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-text-subtle">
                  Demo credentials
                </p>
                <p className="mt-0.5 font-mono text-xs text-text-muted">
                  admin@example.com / password
                </p>
              </div>
            </CardBody>
          </Card>

          <p className="mt-6 text-center text-xs text-text-subtle">
            &copy; {new Date().getFullYear()} Ultimate POS. All rights reserved.
          </p>
        </div>
      </div>
    </div>
  );
}

function readRedirectFrom(state: unknown): string {
  if (state && typeof state === 'object' && 'from' in state) {
    const from = (state as Record<string, unknown>).from;
    if (typeof from === 'string' && from.length > 0) {
      return from;
    }
  }

  return '/dashboard';
}

function BrandPanel(): ReactNode {
  return (
    <div className="relative hidden w-[480px] shrink-0 flex-col justify-between overflow-hidden bg-primary p-10 text-white lg:flex">
      <div className="absolute -top-16 -right-16 h-64 w-64 rounded-full bg-white/10" />
      <div className="absolute -bottom-24 -left-10 h-72 w-72 rounded-full bg-white/5" />

      <div className="relative flex items-center gap-2.5">
        <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-white/15">
          <ion-icon name="storefront-outline" class="text-2xl" aria-hidden="true" />
        </span>
        <span className="text-lg font-semibold">Ultimate POS</span>
      </div>

      <div className="relative">
        <h2 className="text-2xl font-semibold leading-snug">
          Run every corner of your retail business.
        </h2>
        <p className="mt-3 max-w-sm text-sm text-white/80">
          One unified point-of-sale platform for growing multi-location retailers.
        </p>

        <ul className="mt-8 flex flex-col gap-5">
          {VALUE_POINTS.map((point) => (
            <li key={point.title} className="flex items-start gap-3">
              <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-white/15">
                <ion-icon name={point.icon} class="text-lg" aria-hidden="true" />
              </span>
              <div>
                <p className="text-sm font-medium">{point.title}</p>
                <p className="mt-0.5 text-xs text-white/75">{point.description}</p>
              </div>
            </li>
          ))}
        </ul>
      </div>

      <p className="relative text-xs text-white/60">
        Trusted by retailers across Southeast Asia.
      </p>
    </div>
  );
}
