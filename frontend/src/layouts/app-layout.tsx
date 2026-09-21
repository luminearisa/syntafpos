import { type ReactNode, useState } from 'react';
import { Sidebar, useSidebarCollapsed } from '@/components/layout/sidebar';
import { Topbar } from '@/components/layout/topbar';
import { ToastProvider } from '@/components/ui/toast';

export function AppLayout({ children }: { children: ReactNode }) {
  const [collapsed, setCollapsed] = useSidebarCollapsed();
  const [mobileOpen, setMobileOpen] = useState(false);

  return (
    <ToastProvider>
      <div className="min-h-screen bg-background text-text">
        <Sidebar
          collapsed={collapsed}
          mobileOpen={mobileOpen}
          onMobileClose={() => setMobileOpen(false)}
        />

        <div
          className={
            collapsed
              ? 'transition-[padding] duration-200 lg:pl-[60px]'
              : 'transition-[padding] duration-200 lg:pl-60'
          }
        >
          <Topbar
            collapsed={collapsed}
            onToggleCollapsed={() => setCollapsed((value) => !value)}
            onOpenMobile={() => setMobileOpen(true)}
          />

          <main className="mx-auto w-full max-w-7xl p-4 sm:p-6">{children}</main>
        </div>
      </div>
    </ToastProvider>
  );
}
