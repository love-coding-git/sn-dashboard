import AppLogoIcon from '@/components/app-logo-icon';

/** The sidebar brand: the Subnet Watch mark, the name and the subnets it watches. */
export default function AppLogo() {
    return (
        <>
            <AppLogoIcon className="size-8 shrink-0 drop-shadow-sm" />
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="truncate leading-tight font-semibold">Subnet Watch</span>
                <span className="truncate text-[11px] leading-tight text-muted-foreground">SOMA · Yugos</span>
            </div>
        </>
    );
}
