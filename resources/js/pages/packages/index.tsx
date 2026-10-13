import { Head, Link, router, usePoll } from '@inertiajs/react';
import { Loader2, Package2, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useEffect } from 'react';
import Heading from '@/components/heading';
import SyncOverlay from '@/components/sync-overlay';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ECOSYSTEM_LABELS } from '@/types/package';
import type { Package } from '@/types/package';

type PageProps = {
    packages: Package[];
};

export default function PackagesIndex({ packages }: PageProps) {
    const anySyncing = packages.some((pkg) => pkg.is_syncing);

    const { start, stop } = usePoll(2000, {}, { autoStart: false });

    useEffect(() => {
        if (anySyncing) {
            start();
        } else {
            stop();
        }
    }, [anySyncing, start, stop]);

    return (
        <>
            <Head title="Packages" />

            {anySyncing && (
                <SyncOverlay
                    progress={
                        packages.find((pkg) => pkg.is_syncing)?.sync_progress ??
                        0
                    }
                />
            )}

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between">
                    <Heading
                        title="Packages"
                        description="Manage your Composer and npm packages"
                    />
                    <Button asChild>
                        <Link href="/packages/create">
                            <Plus className="mr-2 size-4" />
                            Add Package
                        </Link>
                    </Button>
                </div>

                {packages.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-lg border border-dashed p-12 text-center">
                        <Package2 className="mb-4 size-12 text-muted-foreground" />
                        <h3 className="text-lg font-medium">No packages yet</h3>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Add your first package to get started.
                        </p>
                        <Button asChild className="mt-4">
                            <Link href="/packages/create">
                                <Plus className="mr-2 size-4" />
                                Add Package
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <div className="rounded-lg border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="px-4 py-3 text-left font-medium">
                                        Package
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Ecosystem
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Type
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Versions
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {packages.map((pkg) => (
                                    <tr
                                        key={pkg.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-4 py-3">
                                            <Link
                                                href={`/packages/${pkg.id}`}
                                                className="font-medium hover:underline"
                                            >
                                                {pkg.name}
                                            </Link>
                                            {pkg.description && (
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {pkg.description}
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge variant="outline">
                                                {
                                                    ECOSYSTEM_LABELS[
                                                        pkg.ecosystem
                                                    ]
                                                }
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3">
                                            <TypeBadge type={pkg.type} />
                                        </td>
                                        <td className="px-4 py-3">
                                            {pkg.versions_count ?? 0}
                                        </td>
                                        <td className="px-4 py-3">
                                            <SyncStatus pkg={pkg} />
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={pkg.is_syncing}
                                                    onClick={() =>
                                                        router.post(
                                                            `/packages/${pkg.id}/sync`,
                                                        )
                                                    }
                                                >
                                                    <RefreshCw className="size-3" />
                                                </Button>
                                                <AlertDialog>
                                                    <AlertDialogTrigger asChild>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            disabled={
                                                                pkg.is_syncing
                                                            }
                                                        >
                                                            <Trash2 className="size-3" />
                                                        </Button>
                                                    </AlertDialogTrigger>
                                                    <AlertDialogContent>
                                                        <AlertDialogHeader>
                                                            <AlertDialogTitle>
                                                                Delete package
                                                            </AlertDialogTitle>
                                                            <AlertDialogDescription>
                                                                Are you sure you
                                                                want to delete{' '}
                                                                <strong>
                                                                    {pkg.name}
                                                                </strong>
                                                                ? This action
                                                                cannot be undone
                                                                and will remove
                                                                all associated
                                                                versions and
                                                                data.
                                                            </AlertDialogDescription>
                                                        </AlertDialogHeader>
                                                        <AlertDialogFooter>
                                                            <AlertDialogCancel>
                                                                Cancel
                                                            </AlertDialogCancel>
                                                            <AlertDialogAction
                                                                onClick={() =>
                                                                    router.delete(
                                                                        `/packages/${pkg.id}`,
                                                                    )
                                                                }
                                                            >
                                                                Delete
                                                            </AlertDialogAction>
                                                        </AlertDialogFooter>
                                                    </AlertDialogContent>
                                                </AlertDialog>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

function TypeBadge({ type }: { type: Package['type'] }) {
    const labels = { github: 'GitHub', gitlab: 'GitLab', git: 'Git' };

    return <Badge variant="secondary">{labels[type]}</Badge>;
}

function SyncStatus({ pkg }: { pkg: Package }) {
    if (pkg.is_syncing) {
        return (
            <Badge variant="secondary" className="gap-1">
                <Loader2 className="size-3 animate-spin" />
                Syncing
            </Badge>
        );
    }

    if (pkg.sync_error) {
        return (
            <Badge variant="destructive" title={pkg.sync_error}>
                Error
            </Badge>
        );
    }

    if (pkg.last_synced_at) {
        return (
            <span className="text-xs text-muted-foreground">
                {pkg.last_synced_at}
            </span>
        );
    }

    return <Badge variant="outline">Pending</Badge>;
}

PackagesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Packages',
            href: '/packages',
        },
    ],
};
