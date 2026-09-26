import { useState, type FormEvent, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { authApi } from '@/api/services';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardBody } from '@/components/ui/card';
import { cn } from '@/utils/format';
import { AuthShell } from './auth-shell';

type Status = 'idle' | 'loading' | 'success';
type ErrorVariant = 'error' | 'warning';

const ALERT_STYLES: Record<ErrorVariant, string> = {
  error: 'bg-danger-soft text-danger',
  warning: 'bg-warning-soft text-warning',
};

const ALERT_ICONS: Record<ErrorVariant, string> = {
  error: 'alert-circle-outline',
  warning: 'warning-outline',
};

const RATE_LIMIT_MESSAGE = 'Too many requests. Please wait a moment and try again.';
const DEFAULT_ERROR = 'Unable to send the reset link. Please try again.';

export default function ForgotPasswordPage(): ReactNode {
  const [email, setEmail] = useState('');
  const [status, setStatus] = useState<Status>('idle');
  const [error, setError] = useState<string | null>(null);
  const [errorVariant, setErrorVariant] = useState<ErrorVariant>('error');

  const handleSubmit = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();
    setError(null);
    setStatus('loading');

    try {
      await authApi.forgotPassword(email.trim());
      setStatus('success');
    } catch (submitError) {
      setStatus('idle');

      if (isRateLimited(submitError)) {
        setErrorVariant('warning');
        setError(RATE_LIMIT_MESSAGE);
      } else {
        setErrorVariant('error');
        setError(extractMessage(submitError));
      }
    }
  };

  if (status === 'success') {
    return (
      <AuthShell>
        <Card>
          <CardBody className="p-6 text-center">
            <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-success-soft text-success">
              <ion-icon name="mail-unread-outline" class="text-2xl" aria-hidden="true" />
            </span>

            <h1 className="mt-4 text-lg font-semibold text-text">Check your inbox</h1>
            <p className="mt-2 text-sm text-text-muted">
              If an account exists for <span className="font-medium text-text">{email}</span>,
              a reset link has been sent.
            </p>
            <p className="mt-2 text-xs text-text-subtle">
              The link expires in 60 minutes. Remember to check your spam folder.
            </p>

            <Link to="/login" className="mt-5 inline-block">
              <Button variant="outline" icon="arrow-back-outline">
                Back to login
              </Button>
            </Link>
          </CardBody>
        </Card>
      </AuthShell>
    );
  }

  return (
    <AuthShell>
      <Card>
        <CardBody className="p-6">
          <div className="mb-5 text-center">
            <h1 className="text-lg font-semibold text-text">Forgot your password?</h1>
            <p className="mt-1.5 text-sm text-text-muted">
              Enter your email address and we&apos;ll send you a link to reset it.
            </p>
          </div>

          {error && (
            <div
              role="alert"
              className={cn(
                'mb-4 flex items-start gap-2 rounded-md px-3 py-2.5 text-sm',
                ALERT_STYLES[errorVariant]
              )}
            >
              <ion-icon
                name={ALERT_ICONS[errorVariant]}
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

            <Button
              type="submit"
              size="lg"
              loading={status === 'loading'}
              className="w-full"
            >
              Send reset link
            </Button>
          </form>

          <p className="mt-5 text-center text-xs text-text-subtle">
            Remembered your password?{' '}
            <Link to="/login" className="font-medium text-primary hover:text-primary-dark">
              Sign in
            </Link>
          </p>
        </CardBody>
      </Card>
    </AuthShell>
  );
}

function isRateLimited(error: unknown): boolean {
  return (
    !!error &&
    typeof error === 'object' &&
    'isAxiosError' in error &&
    (error as { response?: { status?: number } }).response?.status === 429
  );
}

function extractMessage(error: unknown): string {
  if (error && typeof error === 'object' && 'isAxiosError' in error) {
    const axiosError = error as {
      response?: { data?: { message?: string } };
    };

    if (axiosError.response?.data?.message) {
      return axiosError.response.data.message;
    }
  }

  if (error instanceof Error) {
    return error.message;
  }

  return DEFAULT_ERROR;
}
