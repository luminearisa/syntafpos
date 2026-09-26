import { type ReactNode, useCallback, useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { Sidebar, useSidebarCollapsed } from '@/components/layout/sidebar';
import { Topbar } from '@/components/layout/topbar';
import { MobileBottomNav } from '@/components/layout/mobile-bottom-nav';
import { ToastProvider } from '@/components/ui/toast';
import { cn } from '@/utils/format';

export function AppLayout({ children }: { children: ReactNode }) {
  const [collapsed, setCollapsed] = useSidebarCollapsed();
  const [mobileOpen, setMobileOpen] = useState(false);
  const location = useLocation();
  const isTill = location.pathname === '/pos';
  const closeMobile = useCallback(() => setMobileOpen(false), []);

  useEffect(() => {
    if (!mobileOpen) return;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = previousOverflow;
    };
  }, [mobileOpen]);

  return (
    <ToastProvider>
      <div className="min-h-dvh bg-background text-text">
        <Sidebar
          collapsed={collapsed}
          mobileOpen={mobileOpen}
          onMobileClose={closeMobile}
        />

        <div
          inert={mobileOpen}
          className={cn(
            'min-h-dvh transition-[padding] duration-200',
            collapsed ? 'lg:pl-[72px]' : 'lg:pl-64'
          )}
        >
          <Topbar
            collapsed={collapsed}
            onToggleCollapsed={() => setCollapsed((value) => !value)}
            onOpenMobile={() => setMobileOpen(true)}
          />

          <main
            className={cn(
              'page-enter mx-auto w-full max-w-[1680px] px-3 py-4 sm:px-5 sm:py-5 lg:px-7 lg:py-6',
              isTill ? 'px-2 py-2 sm:px-3 sm:py-3 lg:px-4 lg:py-4' : 'pb-24 lg:pb-7'
            )}
          >
            {children}
          </main>
        </div>

        {!isTill && !mobileOpen && (
          <MobileBottomNav onOpenMore={() => setMobileOpen(true)} />
        )}
      </div>
    </ToastProvider>
  );
}
