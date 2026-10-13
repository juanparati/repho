import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { BookOpen, Check, Clipboard, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ECOSYSTEM_LABELS } from '@/types/package';
import type { Package, Repository } from '@/types/package';

function CopyButton({ text }: { text: string }) {
    const [copied, setCopied] = useState(false);

    const copy = () => {
        navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <Button
            variant="ghost"
            size="sm"
            className="absolute top-2 right-2"
            onClick={copy}
        >
            {copied ? (
                <Check className="size-3.5" />
            ) : (
                <Clipboard className="size-3.5" />
            )}
        </Button>
    );
}

function buildComposerConfig(
    repoUrl: string,
    authType: Repository['auth_type'],
    authUsername?: string | null,
): string {
    const config: Record<string, unknown> = {
        repositories: [
            {
                type: 'composer',
                url: repoUrl,
            },
        ],
    };

    if (authType === 'basic') {
        config['config'] = {
            'http-basic': {
                [new URL(repoUrl).host]: {
                    username: authUsername ?? '<username>',
                    password: '<password>',
                },
            },
        };
    } else if (authType === 'token') {
        config['config'] = {
            bearer: {
                [new URL(repoUrl).host]: '<token>',
            },
        };
    }

    return JSON.stringify(config, null, 4);
}

function buildNpmrc(
    npmUrl: string,
    authType: Repository['auth_type'],
    authUsername?: string | null,
): string {
    const url = new URL(npmUrl);
    const authKey = `//${url.host}${url.pathname}`;
    const lines = [`@your-scope:registry=${npmUrl}`];

    if (authType === 'basic') {
        lines.push(
            `${authKey}:username=${authUsername ?? '<username>'}`,
            `${authKey}:_password=<base64-of-password>`,
        );
    } else if (authType === 'token') {
        lines.push(`${authKey}:_authToken=<token>`);
    }

    return lines.join('\n');
}

type PageProps = {
    repository: Repository;
    packages: (Pick<Package, 'id' | 'name' | 'ecosystem'> & {
        versions_count: number;
    })[];
    availablePackages: Pick<Package, 'id' | 'name' | 'ecosystem'>[];
};

export default function RepositoriesShow({
    repository,
    packages,
    availablePackages,
}: PageProps) {
    const { appUrl } = usePage<{ appUrl: string }>().props;
    const repoUrl = `${appUrl.replace(/\/+$/, '')}/repo/${repository.slug}`;
    const npmUrl = `${appUrl.replace(/\/+$/, '')}/npm/${repository.slug}/`;

    return (
        <>
            <Head title={repository.name} />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between">
                    <Heading
                        title={repository.name}
                        description={`/repo/${repository.slug}/packages.json`}
                    />
                    <div className="flex items-center gap-2">
                        <Dialog>
                            <DialogTrigger asChild>
                                <Button variant="outline">
                                    <BookOpen className="mr-2 size-4" />
                                    Setup
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="sm:max-w-2xl">
                                <DialogHeader>
                                    <DialogTitle>
                                        Setup Instructions
                                    </DialogTitle>
                                    <DialogDescription>
                                        This repository serves Composer packages
                                        at <code>/repo</code> and npm packages
                                        at <code>/npm</code>.
                                    </DialogDescription>
                                </DialogHeader>
                                <h4 className="text-sm font-medium">
                                    Composer: add to composer.json
                                </h4>
                                <div className="relative">
                                    <CopyButton
                                        text={buildComposerConfig(
                                            repoUrl,
                                            repository.auth_type,
                                            repository.auth_username,
                                        )}
                                    />
                                    <pre className="overflow-auto rounded-lg bg-muted p-4 text-sm">
                                        <code>
                                            {buildComposerConfig(
                                                repoUrl,
                                                repository.auth_type,
                                                repository.auth_username,
                                            )}
                                        </code>
                                    </pre>
                                </div>
                                {repository.auth_type === 'basic' && (
                                    <p className="text-xs text-muted-foreground">
                                        Replace{' '}
                                        <code className="rounded bg-muted px-1 py-0.5">
                                            {'<password>'}
                                        </code>{' '}
                                        with your actual password. You can also
                                        use{' '}
                                        <code className="rounded bg-muted px-1 py-0.5">
                                            composer config http-basic.
                                            {new URL(repoUrl).host}{' '}
                                            {repository.auth_username ??
                                                '<username>'}{' '}
                                            {'<password>'}
                                        </code>{' '}
                                        to store credentials globally.
                                    </p>
                                )}
                                {repository.auth_type === 'token' && (
                                    <p className="text-xs text-muted-foreground">
                                        Replace{' '}
                                        <code className="rounded bg-muted px-1 py-0.5">
                                            {'<token>'}
                                        </code>{' '}
                                        with your actual token. You can also use{' '}
                                        <code className="rounded bg-muted px-1 py-0.5">
                                            composer config bearer.
                                            {new URL(repoUrl).host} {'<token>'}
                                        </code>{' '}
                                        to store the token globally.
                                    </p>
                                )}
                                <h4 className="text-sm font-medium">
                                    npm: add to .npmrc
                                </h4>
                                <div className="relative">
                                    <CopyButton
                                        text={buildNpmrc(
                                            npmUrl,
                                            repository.auth_type,
                                            repository.auth_username,
                                        )}
                                    />
                                    <pre className="overflow-auto rounded-lg bg-muted p-4 text-sm">
                                        <code>
                                            {buildNpmrc(
                                                npmUrl,
                                                repository.auth_type,
                                                repository.auth_username,
                                            )}
                                        </code>
                                    </pre>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Replace{' '}
                                    <code className="rounded bg-muted px-1 py-0.5">
                                        @your-scope
                                    </code>{' '}
                                    with the scope of your packages, or use{' '}
                                    <code className="rounded bg-muted px-1 py-0.5">
                                        registry=
                                    </code>{' '}
                                    to route every package here.
                                </p>
                            </DialogContent>
                        </Dialog>
                        <Button variant="outline" asChild>
                            <Link href={`/repositories/${repository.id}/edit`}>
                                <Pencil className="mr-2 size-4" />
                                Edit
                            </Link>
                        </Button>
                        {repository.slug !== 'default' && (
                            <Button
                                variant="destructive"
                                onClick={() =>
                                    router.delete(
                                        `/repositories/${repository.id}`,
                                    )
                                }
                            >
                                <Trash2 className="mr-2 size-4" />
                                Delete
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="rounded-lg border p-4">
                        <p className="mb-1 text-xs font-medium text-muted-foreground">
                            Authentication
                        </p>
                        <Badge
                            variant={
                                repository.auth_type === 'none'
                                    ? 'secondary'
                                    : 'default'
                            }
                        >
                            {repository.auth_type === 'none'
                                ? 'Public'
                                : repository.auth_type === 'basic'
                                  ? 'Basic Auth'
                                  : 'Token'}
                        </Badge>
                    </div>
                    <div className="rounded-lg border p-4">
                        <p className="mb-1 text-xs font-medium text-muted-foreground">
                            Composer URL
                        </p>
                        <code className="text-xs">{repoUrl}</code>
                    </div>
                    <div className="rounded-lg border p-4">
                        <p className="mb-1 text-xs font-medium text-muted-foreground">
                            npm registry URL
                        </p>
                        <code className="text-xs">{npmUrl}</code>
                    </div>
                    <div className="rounded-lg border p-4">
                        <p className="mb-1 text-xs font-medium text-muted-foreground">
                            Packages
                        </p>
                        <span className="text-sm">{packages.length}</span>
                    </div>
                </div>

                <div>
                    <div className="mb-3 flex items-center justify-between">
                        <h3 className="text-lg font-medium">Packages</h3>
                    </div>

                    {availablePackages.length > 0 && (
                        <Form
                            action={`/repositories/${repository.id}/packages`}
                            method="post"
                            className="mb-4 flex items-end gap-2"
                        >
                            {({ processing }) => (
                                <>
                                    <div className="flex-1">
                                        <Select name="package_id">
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Select a package to add" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {availablePackages.map(
                                                    (pkg) => (
                                                        <SelectItem
                                                            key={pkg.id}
                                                            value={String(
                                                                pkg.id,
                                                            )}
                                                        >
                                                            {pkg.name}{' '}
                                                            <span className="text-muted-foreground">
                                                                (
                                                                {
                                                                    ECOSYSTEM_LABELS[
                                                                        pkg
                                                                            .ecosystem
                                                                    ]
                                                                }
                                                                )
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
                                        <Plus className="mr-1 size-3" />
                                        Add
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}

                    {packages.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No packages in this repository yet.
                        </p>
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
                                            Versions
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
                                                {pkg.versions_count}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.delete(
                                                            `/repositories/${repository.id}/packages/${pkg.id}`,
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
            </div>
        </>
    );
}

RepositoriesShow.layout = {
    breadcrumbs: [
        {
            title: 'Repositories',
            href: '/repositories',
        },
        {
            title: 'Repository Details',
            href: '#',
        },
    ],
};
