import { Link } from 'react-router-dom';

import { Button } from '@/components/ui/button';
import { useAuthStore } from '@/stores/auth-store';

interface QuickAction {
  to: string;
  label: string;
  icon: string;
  permission: string;
}

const ACTIONS: readonly QuickAction[] = [
  // There is no /companies/new route yet, so this points at the list where a
  // company can be created.
  {
    to: '/companies',
    label: 'New Company',
    icon: 'add-outline',
    permission: 'companies.view',
  },
  {
    to: '/users',
    label: 'Users',
    icon: 'people-outline',
    permission: 'users.view',
  },
  {
    to: '/roles',
    label: 'Roles',
    icon: 'key-outline',
    permission: 'roles.view',
  },
  {
    to: '/settings',
    label: 'Settings',
    icon: 'settings-outline',
    permission: 'settings.view',
  },
  {
    to: '/audit-logs',
    label: 'Audit Logs',
    icon: 'document-text-outline',
    permission: 'audit.view',
  },
];

export function QuickActions() {
  const can = useAuthStore((state) => state.can);
  const visible = ACTIONS.filter((action) => can(action.permission));

  if (visible.length === 0) {
    return null;
  }

  return (
    <nav className="flex flex-wrap gap-2" aria-label="Quick actions">
      {visible.map((action) => (
        <Link key={action.to} to={action.to} className="inline-flex">
          <Button variant="secondary" size="sm" icon={action.icon}>
            {action.label}
          </Button>
        </Link>
      ))}
    </nav>
  );
}
