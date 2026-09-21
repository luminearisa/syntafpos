import { useState, type FormEvent, type ReactNode } from 'react';
import { authApi } from '@/api/services';
import { useToast } from '@/components/ui/toast';
import { Card, CardBody, CardHeader } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/state';

const PASSWORD_MIN_LENGTH = 8;

export default function ChangePasswordPage(): ReactNode {
  const { toast } = useToast();

  const [currentPassword, setCurrentPassword] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);

  const confirmMismatch =
    passwordConfirmation.length > 0 && password !== passwordConfirmation;

  const canSubmit =
    currentPassword.length > 0 &&
    password.length >= PASSWORD_MIN_LENGTH &&
    passwordConfirmation.length >= PASSWORD_MIN_LENGTH &&
    password === passwordConfirmation &&
    !isSubmitting;

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
      await authApi.changePassword(currentPassword, password, passwordConfirmation);

      toast({ title: 'Password updated', message: 'Sign in with your new password next time.', variant: 'success' });

      setCurrentPassword('');
      setPassword('');
      setPasswordConfirmation('');
    } catch (submitError) {
      const { message, errors } = extractError(submitError);
      setFormError(message);
      setFieldErrors(errors);
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Change Password"
        description="Use a strong, unique password to keep your account secure."
      />

      <div className="mx-auto w-full max-w-2xl">
        <Card>
          <CardHeader
            title="Update your password"
            description="Minimum 8 characters. A mix of letters, numbers and symbols is recommended."
          />
          <CardBody>
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
                name="current_password"
                type="password"
                label="Current password"
                placeholder="Enter your current password"
                icon="lock-closed-outline"
                autoComplete="current-password"
                autoFocus
                required
                error={fieldErrors.current_password}
                value={currentPassword}
                onChange={(event) => setCurrentPassword(event.target.value)}
              />

              <Input
                name="password"
                type="password"
                label="New password"
                placeholder="At least 8 characters"
                icon="lock-closed-outline"
                autoComplete="new-password"
                required
                error={fieldErrors.password}
                hint={
                  password.length > 0 && password.length < PASSWORD_MIN_LENGTH
                    ? `Add at least ${PASSWORD_MIN_LENGTH - password.length} more characters.`
                    : undefined
                }
                value={password}
                onChange={(event) => setPassword(event.target.value)}
              />

              <Input
                name="password_confirmation"
                type="password"
                label="Confirm new password"
                placeholder="Re-enter your new password"
                icon="lock-closed-outline"
                autoComplete="new-password"
                required
                error={
                  fieldErrors.password_confirmation ??
                  (confirmMismatch ? 'Passwords do not match.' : undefined)
                }
                value={passwordConfirmation}
                onChange={(event) => setPasswordConfirmation(event.target.value)}
              />

              <Button type="submit" size="lg" loading={isSubmitting} disabled={!canSubmit} className="w-full">
                Update password
              </Button>
            </form>
          </CardBody>
        </Card>
      </div>
    </div>
  );
}

function extractError(error: unknown): {
  message: string;
  errors: Record<string, string>;
} {
  const errors: Record<string, string> = {};

  if (error && typeof error === 'object' && 'isAxiosError' in error) {
    const axiosError = error as {
      response?: {
        data?: {
          message?: string;
          errors?: Record<string, string[]>;
        };
      };
    };

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

  return { message: 'Unable to update your password. Please try again.', errors };
}
