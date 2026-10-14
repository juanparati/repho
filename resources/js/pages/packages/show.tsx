import { Form, Head, Link, router, usePoll } from '@inertiajs/react';
import { Download, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts';
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
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ECOSYSTEM_LABELS } from '@/types/package';
import type { Package, Repository, Version } from '@/types/package';

const chartConfig = {
    downloads: {
        label: 'Downloads',
        color: 'var(--chart-1)',
    },
} satisfies ChartConfig;

type PageProps = {
    package: Package & {
        repositories?: Pick<Repository, 'id' | 'name' | 'slug'>[];
    };
    versions: Version[];
    availableRepositories: Pick<Repository, 'id' | 'name' | 'slug'>[];
    downloadStats: { date: string; downloads: number }[];
};

export default function PackagesShow({
    package: pkg,
    versions,
    availableRepositories,
    downloadStats,
}: PageProps) {
    const [showAddRepository, setShowAddRepository] = useState(false);

    const { start, stop } = usePoll(2000, {}, { autoStart: false });

    useEffect(() => {
        if (pkg.is_syncing) {
            start();
        } else {
            stop();
        }
    }, [pkg.is_syncing, start, stop]);

    return (
        <>
            <Head title={pkg.name} />

            {pkg.is_syncing && <SyncOverlay progress={pkg.sync_progress} />}

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between">
                    <Heading
                        title={pkg.name}
                        description={pkg.description ?? undefined}
                    />
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            asChild
                            disabled={pkg.is_syncing}
                        >
                            <Link href={`/packages/${pkg.id}/edit`}>
                                <Pencil className="mr-2 size-4" />
                                Edit
                            </Link>
                        </Button>
                        <Button
                            variant="outline"
                            disabled={pkg.is_syncing}
                            onClick={() =>
                                router.post(`/packages/${pkg.id}/sync`)
                            }
                        >
                            <RefreshCw className="mr-2 size-4" />
                            Sync
                        </Button>
                        <AlertDialog>
                            <AlertDialogTrigger asChild>
                                <Button
                                    variant="destructive"
                                    disabled={pkg.is_syncing}
                                >
                                    <Trash2 className="mr-2 size-4" />
                                    Delete
                                </Button>
                            </AlertDialogTrigger>
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>
                                        Delete package
                                    </AlertDialogTitle>
                                    <AlertDialogDescription>
                                        Are you sure you want to delete{' '}
                                        <strong>{pkg.name}</strong>? This action
                                        cannot be undone and will remove all
                                        associated versions and data.
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                    <AlertDialogCancel>
                                        Cancel
                                    </AlertDialogCancel>
                                    <AlertDialogAction
                                        onClick={() =>
                                            router.delete(`/packages/${pkg.id}`)
                                        }
                                    >
                                        Delete
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <InfoCard label="Ecosystem">
                        <Badge variant="outline">
                            {ECOSYSTEM_LABELS[pkg.ecosystem]}
                        </Badge>
                    </InfoCard>
                    <InfoCard label="Type">
                        <Badge variant="secondary">
                            {
                                {
                                    github: 'GitHub',
                                    gitlab: 'GitLab',
                                    git: 'Git',
                                }[pkg.type]
                            }
                        </Badge>
                    </InfoCard>
                    <InfoCard label="Repository">
                        <a
                            href={pkg.repository_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-sm hover:underline"
                        >
                            {pkg.repository_url}
                        </a>
                    </InfoCard>
                    {pkg.subdirectory && (
                        <InfoCard label="Subdirectory">
                            <code className="text-sm">{pkg.subdirectory}</code>
                        </InfoCard>
                    )}
                    <InfoCard label="Last Synced">
                        <span className="text-sm">
                            {pkg.last_synced_at ?? 'Never'}
                        </span>
                    </InfoCard>
                    <InfoCard label="Download Dists">
                        <Badge
                            variant={
                                pkg.download_dists || pkg.ecosystem === 'npm'
                                    ? 'default'
                                    : 'secondary'
                            }
                        >
                            {pkg.ecosystem === 'npm'
                                ? 'Always (tarballs built on sync)'
                                : pkg.download_dists
                                  ? 'Enabled'
                                  : 'Disabled'}
                        </Badge>
                    </InfoCard>
                </div>

                <div>
                    <div className="mb-3 flex items-center justify-between">
                        <h3 className="text-lg font-medium">Repositories</h3>
                        {availableRepositories.length > 0 && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setShowAddRepository(!showAddRepository)
                                }
                            >
                                <Plus className="size-4" />
                            </Button>
                        )}
                    </div>

                    {showAddRepository && availableRepositories.length > 0 && (
                        <Form
                            action={`/packages/${pkg.id}/repositories`}
                            method="post"
                            className="mb-4 flex items-end gap-2"
                            onSuccess={() => setShowAddRepository(false)}
                        >
                            {({ processing }) => (
                                <>
                                    <div className="flex-1">
                                        <Select name="repository_id">
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Select a repository to add" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {availableRepositories.map(
                                                    (repo) => (
                                                        <SelectItem
                                                            key={repo.id}
                                                            value={String(
                                                                repo.id,
                                                            )}
                                                        >
                                                            {repo.name}{' '}
                                                            <span className="text-muted-foreground">
                                                                ({repo.slug})
                                                            </span>
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        size="sm"
                                    >
                                        Add
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}

                    {!pkg.repositories || pkg.repositories.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Not assigned to any repositories yet.
                        </p>
                    ) : (
                        <div className="rounded-lg border">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="px-4 py-3 text-left font-medium">
                                            Repository
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            Slug
                                        </th>
                                        <th className="px-4 py-3 text-right font-medium">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {pkg.repositories.map((repo) => (
                                        <tr
                                            key={repo.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="px-4 py-3">
                                                <Link
                                                    href={`/repositories/${repo.id}`}
                                                    className="font-medium hover:underline"
                                                >
                                                    {repo.name}
                                                </Link>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {repo.slug}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.delete(
                                                            `/packages/${pkg.id}/repositories/${repo.id}`,
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="size-3" />
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                {pkg.sync_error && (
                    <div className="rounded-lg border border-destructive/50 bg-destructive/10 p-4">
                        <h3 className="text-sm font-medium text-destructive">
                            Sync Error
                        </h3>
                        <p className="mt-1 text-sm text-destructive/80">
                            {pkg.sync_error}
                        </p>
                    </div>
                )}

                <div>
                    <h3 className="mb-3 text-lg font-medium">
                        Versions ({versions.length})
                    </h3>

                    {versions.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No versions found. Try syncing the package.
                        </p>
                    ) : (
                        <div className="max-h-[300px] overflow-y-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="sticky top-0 z-10 bg-muted/50">
                                    <tr className="border-b">
                                        <th className="px-4 py-3 text-left font-medium">
                                            Version
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            Reference
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            Released
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            Added at
                                        </th>
                                        <th className="px-4 py-3 text-right font-medium">
                                            Dist
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {versions.map((v) => (
                                        <tr
                                            key={v.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="px-4 py-3 font-mono">
                                                {v.version}
                                            </td>
                                            <td
                                                className="px-4 py-3 font-mono text-xs text-muted-foreground"
                                                title={v.integrity ?? undefined}
                                            >
                                                {v.reference.substring(0, 12)}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {v.released_at ?? '-'}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {v.created_at}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                {v.dist_url && (
                                                    <a
                                                        href={v.dist_url}
                                                        download
                                                    >
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                        >
                                                            <Download className="size-4" />
                                                        </Button>
                                                    </a>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Downloads</CardTitle>
                        <CardDescription>
                            {downloadStats
                                .reduce((sum, d) => sum + d.downloads, 0)
                                .toLocaleString()}{' '}
                            downloads in the last 30 days
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ChartContainer
                            config={chartConfig}
                            className="h-[300px] w-full"
                        >
                            <BarChart data={downloadStats}>
                                <CartesianGrid vertical={false} />
                                <XAxis
                                    dataKey="date"
                                    tickLine={false}
                                    tickMargin={10}
                                    tickFormatter={(value: string) => {
                                        const date = new Date(
                                            value + 'T00:00:00',
                                        );

                                        return date.toLocaleDateString(
                                            'en-US',
                                            {
                                                month: 'short',
                                                day: 'numeric',
                                            },
                                        );
                                    }}
                                    interval="preserveStartEnd"
                                />
                                <YAxis
                                    tickLine={false}
                                    axisLine={false}
                                    allowDecimals={false}
                                />
                                <ChartTooltip
                                    content={
                                        <ChartTooltipContent
                                            labelFormatter={(value) => {
                                                const date = new Date(
                                                    value + 'T00:00:00',
                                                );

                                                return date.toLocaleDateString(
                                                    'en-US',
                                                    {
                                                        weekday: 'short',
                                                        month: 'short',
                                                        day: 'numeric',
                                                    },
                                                );
                                            }}
                                        />
                                    }
                                />
                                <Bar
                                    dataKey="downloads"
                                    fill="var(--color-downloads)"
                                    radius={[4, 4, 0, 0]}
                                />
                            </BarChart>
                        </ChartContainer>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

function InfoCard({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="rounded-lg border p-4">
            <p className="mb-1 text-xs font-medium text-muted-foreground">
                {label}
            </p>
            {children}
        </div>
    );
}

PackagesShow.layout = {
    breadcrumbs: [
        {
            title: 'Packages',
            href: '/packages',
        },
        {
            title: 'Package Details',
            href: '#',
        },
    ],
};
