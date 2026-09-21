import { useState, type FormEvent, type ReactNode } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { authApi } from '@/api/services';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardBody } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/state';

const PASSWORD_MIN_LENGTH = 8;

export default function ResetPasswordPage(): ReactNode {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();

  const token = searchParams.get('token') ?? '';
  const email = searchParams.get('email') ?? '';

  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [isDone, setIsDone] = useState(false);

  if (!token || !email) {
    return (
      <AuthShell>
        <Card>
          <ErrorState
            message="This password reset link is incomplete or invalid."
            className="py-10"
          />
          <div className="flex justify-center pb-6">
            <Link to="/forgot-password">
              <Button variant="outline" icon="mail-outline">
                Request a new link
              </Button>
            </Link>
          </div>
        </Card>
      </AuthShell>
    );
  }

  const strength = passwordStrength(password);
  const confirmMismatch =
    passwordConfirmation.length > 0 && password !== passwordConfirmation;

  const handleSubmit = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();

    if (password !== passwordConfirmation) {
      setFieldErrors({ password_confirmation: 'Passwords do not match.' });
      return;
    }

    setFieldErrors({});
    setFormError(null);
    setIsSubmitting(true);

    try {
      await authApi.resetPassword(token, email, password, passwordConfirmation);
      setIsDone(true);
    } catch (submitError) {
      const { message, errors } = extractError(submitError);
      setFormError(message);
      setFieldErrors(errors);
    } finally {
      setIsSubmitting(false);
    }
  };

  if (isDone) {
    return (
      <AuthShell>
        <Card>
          <CardBody className="p-6 text-center">
            <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-success-soft text-success">
              <ion-icon
                name="checkmark-done-circle-outline"
                class="text-2xl"
                aria-hidden="true"
              />
            </span>

            <h1 className="mt-4 text-lg font-semibold text-text">Password reset</h1>
            <p className="mt-2 text-sm text-text-muted">
              Your password has been updated. You can now sign in with your new password.
            </p>

            <Button
              variant="primary"
              icon="log-in-outline"
              className="mt-5 w-full"
              onClick={() => navigate('/login', { replace: true })}
            >
              Continue to login
            </Button>
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
            <h1 className="text-lg font-semibold text-text">Set a new password</h1>
            <p className="mt-1.5 text-sm text-text-muted">
              Choose a strong password you haven&apos;t used before.
            </p>
          </div>

          {formError && (
            <div
              role="alert"
              className="mb-4 flex items-start gap-2 rounded-md bg-danger-soft px-3 py-2.5 text-sm text-danger"
            >
              <ion-icon name="alert-circle-outline" class="mt-0.5 shrink-0" aria-hidden="true" />
              <span>{formError}</span>
            </div>
          )}

          <form className="flex flex-col gap-4" onSubmit={handleSubmit} noValidate>
            <Input
              name="email"
              type="email"
              label="Email address"
              icon="mail-outline"
              autoComplete="email"
              readOnly
              value={email}
            />

            <Input
              name="password"
              type="password"
              label="New password"
              placeholder="At least 8 characters"
              icon="lock-closed-outline"
              autoComplete="new-password"
              autoFocus
              required
              error={fieldErrors.password}
              value={password}
              onChange={(event) => setPassword(event.target.value)}
            />

            {password.length > 0 && (
              <div className="-mt-2 flex items-center gap-2">
                <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-alt">
                  <div
                    className={`h-full rounded-full transition-all ${strength.bar}`}
                    style={{ width: `${strength.percent}%` }}
                  />
                </div>
                <span className={`text-xs font-medium ${strength.text}`}>
                  {strength.label}
                </span>
              </div>
            )}

            <Input
              name="password_confirmation"
              type="password"
              label="Confirm new password"
              placeholder="Re-enter your password"
              icon="lock-closed-outline"
              autoComplete="new-password"
              required
              error={
                fieldErrors.password_confirmation ??
                (confirmMismatch ? 'Passwords do not match.' : undefined)
              }
              hint="Use 8+ characters with a mix of letters, numbers and symbols."
              value={passwordConfirmation}
              onChange={(event) => setPasswordConfirmation(event.target.value)}
            />

            <Button
              type="submit"
              size="lg"
              loading={isSubmitting}
              disabled={password.length < PASSWORD_MIN_LENGTH || confirmMismatch}
              className="w-full"
            >
              Reset password
            </Button>
          </form>

          <p className="mt-5 text-center text-xs text-text-subtle">
            Problem with the link?{' '}
            <Link to="/forgot-password" className="font-medium text-primary hover:text-primary-dark">
              Request another
            </Link>
          </p>
        </CardBody>
      </Card>
    </AuthShell>
  );
}

function AuthShell({ children }: { children: ReactNode }): ReactNode {
  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-4 py-10 sm:px-6">
      <div className="w-full max-w-md">
        <div className="mb-6 flex items-center justify-center gap-2.5">
          <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary text-white">
            <ion-icon name="storefront-outline" class="text-xl" aria-hidden="true" />
          </span>
          <span className="text-base font-semibold text-text">Ultimate POS</span>
        </div>
        {children}
      </div>
    </div>
  );
}

function passwordStrength(value: string): {
  percent: number;
  label: string;
  bar: string;
  text: string;
} {
  let score = 0;
  if (value.length >= 8) score += 1;
  if (value.length >= 12) score += 1;
  if (/[A-Z]/.test(value) && /[a-z]/.test(value)) score += 1;
  if (/\d/.test(value)) score += 1;
  if (/[^A-Za-z0-9]/.test(value)) score += 1;

  if (score <= 2) {
    return {
      percent: 33,
      label: 'Weak',
      bar: 'bg-danger',
      text: 'text-danger',
    };
  }

  if (score <= 3) {
    return {
      percent: 66,
      label: 'Good',
      bar: 'bg-warning',
      text: 'text-warning',
    };
  }

  return {
    percent: 100,
    label: 'Strong',
    bar: 'bg-success',
    text: 'text-success',
  };
}

function extractError(error: unknown): {
  message: string;
  errors: Record<string, string>;
} {
  const errors: Record<string, string> = {};

  if (error && typeof error === 'object' && 'isAxiosError' in error) {
    const axiosError = error as {
      response?: {
        status?: number;
        data?: {
          message?: string;
          errors?: Record<string, string[]>;
        };
      };
    };

    if (axiosError.response?.status === 429) {
      return {
        message: 'Too many attempts. Please wait a moment and try again.',
        errors,
      };
    }

    const payload = axiosError.response?.data;

    if (payload?.errors) {
      Object.entries(payload.errors).forEach(([key, messages]) => {
        if (Array.isArray(messages) && messages.length > 0) {
          errors[key] = messages[0] as string;
        }
      });
    }

    if (payload?.message) {
      return { message: payload.message, errors };
    }
  }

  if (error instanceof Error) {
    return { message: error.message, errors };
  }

  return { message: 'Unable to reset your password. Please try again.', errors };
}
