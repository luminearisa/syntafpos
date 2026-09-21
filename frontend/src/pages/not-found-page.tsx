import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';

export default function NotFoundPage() {
  return (
    <div className="flex min-h-screen flex-col items-center justify-center gap-3 bg-background px-6 text-center">
      <span className="flex h-14 w-14 items-center justify-center rounded-full bg-primary-soft text-primary">
        <ion-icon name="compass-outline" class="text-3xl" aria-hidden="true" />
      </span>

      <h1 className="text-xl font-semibold text-text">Page not found</h1>
      <p className="max-w-sm text-sm text-text-muted">
        The page you are looking for does not exist or has been moved.
      </p>

      <Link to="/dashboard">
        <Button variant="primary" icon="arrow-back-outline">
          Back to dashboard
        </Button>
      </Link>
    </div>
  );
}
