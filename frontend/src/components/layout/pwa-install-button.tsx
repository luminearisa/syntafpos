import { useEffect, useState } from 'react';
import { useToast } from '@/components/ui/toast';

type InstallPromptEvent = Event & {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
};

function isIOSDevice(): boolean {
  return /iphone|ipad|ipod/i.test(navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function isStandalone(): boolean {
  return window.matchMedia('(display-mode: standalone)').matches ||
    (navigator as Navigator & { standalone?: boolean }).standalone === true;
}

export function PwaInstallButton() {
  const [promptEvent, setPromptEvent] = useState<InstallPromptEvent | null>(null);
  const [installed, setInstalled] = useState(isStandalone);
  const { toast } = useToast();
  const isIOS = isIOSDevice();

  useEffect(() => {
    const handlePrompt = (event: Event) => {
      event.preventDefault();
      setPromptEvent(event as InstallPromptEvent);
    };
    const handleInstalled = () => {
      setInstalled(true);
      setPromptEvent(null);
    };

    window.addEventListener('beforeinstallprompt', handlePrompt);
    window.addEventListener('appinstalled', handleInstalled);

    return () => {
      window.removeEventListener('beforeinstallprompt', handlePrompt);
      window.removeEventListener('appinstalled', handleInstalled);
    };
  }, []);

  if (installed || (!promptEvent && !isIOS)) {
    return null;
  }

  const install = async () => {
    if (!promptEvent) {
      toast({
        title: 'Install SyntafPOS',
        message: 'Tap Share in Safari, then choose “Add to Home Screen”.',
        variant: 'info',
      });
      return;
    }

    await promptEvent.prompt();
    const result = await promptEvent.userChoice;
    if (result.outcome === 'accepted') {
      setInstalled(true);
    }
    setPromptEvent(null);
  };

  return (
    <button
      type="button"
      onClick={() => void install()}
      className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-primary/15 bg-primary-soft/70 px-2.5 text-xs font-semibold text-primary transition-colors hover:bg-primary-soft sm:px-3"
      aria-label="Install SyntafPOS app"
      title="Install SyntafPOS on this device"
    >
      <ion-icon name="download-outline" class="text-base" aria-hidden="true" />
      <span className="hidden sm:inline">Install</span>
    </button>
  );
}
