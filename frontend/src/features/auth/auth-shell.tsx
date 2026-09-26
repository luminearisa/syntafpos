import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';

export function AuthShell({ children }: { children: ReactNode }) {
  return (
    <main className="relative flex min-h-dvh items-center justify-center overflow-hidden bg-background px-4 py-8 sm:px-6">
      <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,rgba(49,88,201,0.09),transparent_48%)]" />
      <div className="relative w-full max-w-[440px]">
        <Link to="/login" className="mb-7 flex items-center justify-center gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-primary-light to-primary text-white shadow-md">
            <ion-icon name="calculator-outline" class="text-xl" aria-hidden="true" />
          </span>
          <span>
            <span className="block text-base font-bold tracking-tight text-text">SyntafPOS</span>
            <span className="block text-[10px] font-semibold tracking-[0.14em] text-text-muted uppercase">Retail operations</span>
          </span>
        </Link>
        {children}
        <p className="mt-6 text-center text-[11px] text-text-muted">
          Secure access to your SyntafPOS workspace.
        </p>
      </div>
    </main>
  );
}
