import { useState, type FormEvent, type ReactNode } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuthStore } from '@/stores/auth-store';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardBody } from '@/components/ui/card';

const VALUE_POINTS: { icon: string; title: string; description: string }[] = [
  {
    icon: 'business-outline',
    title: 'One connected workspace',
    description: 'Manage companies, branches and registers from a single view.',
  },
  {
    icon: 'analytics-outline',
    title: 'Decisions with clarity',
    description: 'Keep sales, stock and everyday operations in sync.',
  },
  {
    icon: 'shield-checkmark-outline',
    title: 'Access that makes sense',
    description: 'Give every teammate the right tools for their role.',
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
    <div className="relative flex min-h-dvh overflow-hidden bg-background">
      <BrandPanel />

      <main className="relative flex min-h-dvh flex-1 items-center justify-center px-4 py-8 sm:px-8 lg:px-12">
        <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,rgba(49,88,201,0.08),transparent_45%)]" />
        <div className="relative w-full max-w-[430px]">
          <div className="mb-8 flex items-center gap-3 lg:hidden">
            <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-primary-light to-primary text-white shadow-md">
              <ion-icon name="calculator-outline" class="text-2xl" aria-hidden="true" />
            </span>
            <div>
              <p className="text-base font-bold tracking-tight text-text">SyntafPOS</p>
              <p className="text-[10px] font-semibold tracking-[0.14em] text-text-muted uppercase">Retail operations</p>
            </div>
          </div>

          <div className="mb-6">
            <span className="inline-flex items-center gap-1.5 rounded-full border border-primary/10 bg-white px-2.5 py-1 text-[10px] font-bold tracking-[0.12em] text-primary uppercase shadow-xs">
              <ion-icon name="lock-closed-outline" aria-hidden="true" />
              Secure workspace
            </span>
            <h1 className="mt-4 text-[29px] font-bold tracking-[-0.04em] text-text sm:text-[34px]">
              Welcome back
            </h1>
            <p className="mt-1.5 text-sm leading-relaxed text-text-muted">
              Sign in to manage your business operations.
            </p>
          </div>

          <Card className="rounded-2xl border-white/80 shadow-lg shadow-slate-900/[0.06]">
            <CardBody className="p-5 sm:p-6">
              {error && (
                <div
                  role="alert"
                  className="mb-5 flex items-start gap-2.5 rounded-xl border border-danger/10 bg-danger-soft px-3.5 py-3 text-sm text-danger"
                >
                  <ion-icon
                    name="alert-circle-outline"
                    class="mt-0.5 shrink-0 text-lg"
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
                    className="pr-11"
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword((value) => !value)}
                    className="absolute top-[2.45rem] right-2.5 flex h-8 w-8 items-center justify-center rounded-lg text-text-subtle transition-colors hover:bg-surface-alt hover:text-text"
                    aria-label={showPassword ? 'Hide password' : 'Show password'}
                    aria-pressed={showPassword}
                  >
                    <ion-icon
                      name={showPassword ? 'eye-off-outline' : 'eye-outline'}
                      aria-hidden="true"
                    />
                  </button>
                </div>

                <div className="flex items-center justify-between gap-3 pt-0.5">
                  <label className="flex cursor-pointer items-center gap-2 text-xs font-medium text-text-muted">
                    <input
                      type="checkbox"
                      className="h-4 w-4 rounded border-border accent-primary focus:ring-primary"
                      checked={remember}
                      onChange={(event) => setRemember(event.target.checked)}
                    />
                    Remember me
                  </label>

                  <Link
                    to="/forgot-password"
                    className="text-xs font-semibold text-primary transition-colors hover:text-primary-dark"
                  >
                    Forgot password?
                  </Link>
                </div>

                <Button type="submit" size="lg" loading={isLoading} className="mt-1 w-full">
                  Sign in
                  <ion-icon name="arrow-forward-outline" class="ml-1 text-base" aria-hidden="true" />
                </Button>
              </form>
            </CardBody>
          </Card>

          <div className="mt-5 flex items-center justify-center gap-1.5 text-[11px] text-text-subtle">
            <ion-icon name="shield-checkmark-outline" class="text-success" aria-hidden="true" />
            Your account is protected with secure authentication.
          </div>
          <p className="mt-8 text-center text-[11px] text-text-muted">
            &copy; {new Date().getFullYear()} SyntafPOS. All rights reserved.
          </p>
        </div>
      </main>
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
    <aside className="relative hidden w-[43%] min-h-dvh shrink-0 flex-col justify-between overflow-hidden bg-[#101d32] p-10 text-white xl:p-14 lg:flex">
      <div className="pointer-events-none absolute -right-24 -top-24 h-[28rem] w-[28rem] rounded-full bg-primary/30 blur-3xl" />
      <div className="pointer-events-none absolute -bottom-32 -left-28 h-[32rem] w-[32rem] rounded-full bg-cyan-500/10 blur-3xl" />
      <div className="pointer-events-none absolute inset-0 opacity-[0.07] [background-image:radial-gradient(rgba(255,255,255,0.8)_0.7px,transparent_0.7px)] [background-size:18px_18px]" />

      <div className="relative flex items-center gap-3">
        <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-primary-light to-primary text-white shadow-lg shadow-blue-950/40">
          <ion-icon name="calculator-outline" class="text-2xl" aria-hidden="true" />
        </span>
        <div>
          <p className="text-lg font-bold tracking-tight">SyntafPOS</p>
          <p className="text-[10px] font-semibold tracking-[0.15em] text-slate-400 uppercase">Retail operations</p>
        </div>
      </div>

      <div className="relative my-12 max-w-xl">
        <span className="mb-4 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.06] px-3 py-1.5 text-[10px] font-semibold tracking-[0.12em] text-blue-100 uppercase">
          <span className="h-1.5 w-1.5 rounded-full bg-emerald-300" />
          Built for teams that move
        </span>
        <h2 className="text-4xl font-bold leading-[1.12] tracking-[-0.045em] xl:text-[44px]">
          Everything in your store, <span className="text-[#9eb5ff]">working together.</span>
        </h2>
        <p className="mt-4 max-w-md text-sm leading-7 text-slate-300">
          A clear, connected way to run sales, inventory and daily operations across every location.
        </p>

        <ul className="mt-9 flex flex-col gap-5">
          {VALUE_POINTS.map((point) => (
            <li key={point.title} className="flex items-start gap-3.5">
              <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-white/10 bg-white/[0.07] text-blue-100">
                <ion-icon name={point.icon} class="text-xl" aria-hidden="true" />
              </span>
              <div className="pt-0.5">
                <p className="text-sm font-semibold">{point.title}</p>
                <p className="mt-1 max-w-sm text-xs leading-relaxed text-slate-400">{point.description}</p>
              </div>
            </li>
          ))}
        </ul>
      </div>

      <p className="relative flex items-center gap-2 text-[11px] text-slate-300/80">
        <ion-icon name="globe-outline" aria-hidden="true" />
        One workspace. Every location. Every day.
      </p>
    </aside>
  );
}
