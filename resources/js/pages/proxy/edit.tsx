import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { BookOpen, Copy, Globe, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ECOSYSTEM_LABELS } from '@/types/package';
import type { ProxySetting, ProxyUpstream } from '@/types/package';

type PageProps = {
    settings: ProxySetting;
    upstreams: ProxyUpstream[];
};

export default function ProxyEdit({ settings, upstreams }: PageProps) {
    const [composerEnabled, setComposerEnabled] = useState(
        settings.composer_enabled,
    );
    const [npmEnabled, setNpmEnabled] = useState(settings.npm_enabled);
    const [authType, setAuthType] = useState(settings.auth_type);
    const { appUrl } = usePage<{ appUrl: string }>().props;
    const proxyUrl = `${appUrl.replace(/\/+$/, '')}/proxy`;

    return (
        <>
            <Head title="Proxy Settings" />

            <div className="mx-auto max-w-2xl space-y-6 p-4">
                <div className="flex items-center justify-between">
                    <Heading
                        title="Proxy Settings"
                        description="Configure the Composer and npm proxies to cache upstream packages for offline use."
                    />
                    <ProxyInstructionsDialog
                        proxyUrl={proxyUrl}
                        authType={settings.auth_type}
                    />
                </div>

                <Form action="/proxy" method="put" className="space-y-6">
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="composer_enabled"
                                value={composerEnabled ? '1' : '0'}
                            />
                            <input
                                type="hidden"
                                name="npm_enabled"
                                value={npmEnabled ? '1' : '0'}
                            />
                            <div className="space-y-2">
                                <label className="flex items-center gap-2">
                                    <Checkbox
                                        checked={composerEnabled}
                                        onCheckedChange={(checked) =>
                                            setComposerEnabled(checked === true)
                                        }
                                    />
                                    <span className="text-sm font-medium">
                                        Enable Composer proxy
                                    </span>
                                    <code className="text-xs text-muted-foreground">
                                        /proxy
                                    </code>
                                </label>
                                <InputError message={errors.composer_enabled} />
                                <label className="flex items-center gap-2">
                                    <Checkbox
                                        checked={npmEnabled}
                                        onCheckedChange={(checked) =>
                                            setNpmEnabled(checked === true)
                                        }
                                    />
                                    <span className="text-sm font-medium">
                                        Enable npm proxy
                                    </span>
                                    <code className="text-xs text-muted-foreground">
                                        /proxy/npm
                                    </code>
                                </label>
                                <InputError message={errors.npm_enabled} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="metadata_cache_ttl">
                                    Metadata Cache TTL (seconds)
                                </Label>
                                <Input
                                    id="metadata_cache_ttl"
                                    name="metadata_cache_ttl"
                                    type="number"
                                    defaultValue={settings.metadata_cache_ttl}
                                    required
                                    min={60}
                                />
                                <InputError
                                    message={errors.metadata_cache_ttl}
                                />
                            </div>

                            <fieldset className="space-y-4 rounded-lg border p-4">
                                <legend className="px-2 text-sm font-medium">
                                    Client Authentication
                                </legend>
                                <p className="text-xs text-muted-foreground">
                                    How Composer and npm clients authenticate to
                                    this proxy.
                                </p>

                                <div className="grid gap-2">
                                    <Label htmlFor="auth_type">
                                        Authentication
                                    </Label>
                                    <Select
                                        name="auth_type"
                                        defaultValue={settings.auth_type}
                                        onValueChange={setAuthType}
                                    >
                                        <SelectTrigger className="w-full">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">
                                                None (Public)
                                            </SelectItem>
                                            <SelectItem value="basic">
                                                Basic Auth
                                            </SelectItem>
                                            <SelectItem value="token">
                                                Token
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.auth_type} />
                                </div>

                                {authType === 'basic' && (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="auth_username">
                                                Username
                                            </Label>
                                            <Input
                                                id="auth_username"
                                                name="auth_username"
                                                defaultValue={
                                                    settings.auth_username ?? ''
                                                }
                                                required
                                            />
                                            <InputError
                                                message={errors.auth_username}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="auth_password">
                                                Password
                                            </Label>
                                            <Input
                                                id="auth_password"
                                                name="auth_password"
                                                type="password"
                                                placeholder="Leave blank to keep current"
                                            />
                                            <InputError
                                                message={errors.auth_password}
                                            />
                                        </div>
                                    </>
                                )}

                                {authType === 'token' && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="auth_token">
                                            Token
                                        </Label>
                                        <Input
                                            id="auth_token"
                                            name="auth_token"
                                            type="password"
                                            placeholder="Leave blank to keep current"
                                        />
                                        <InputError
                                            message={errors.auth_token}
                                        />
                                    </div>
                                )}
                            </fieldset>

                            <Button type="submit" disabled={processing}>
                                {processing ? 'Saving...' : 'Save Settings'}
                            </Button>
                        </>
                    )}
                </Form>

                <div className="space-y-4 pt-4">
                    <Heading
                        title="Upstreams"
                        description="Upstream Composer repositories and npm registries to proxy. Within an ecosystem, first match wins by priority order."
                        variant="small"
                    />
                    <Button asChild size="sm">
                        <Link href="/proxy/upstreams/create">
                            <Plus className="mr-2 size-4" />
                            Add Upstream
                        </Link>
                    </Button>

                    {upstreams.length === 0 ? (
                        <div className="flex flex-col items-center justify-center rounded-lg border border-dashed p-12 text-center">
                            <Globe className="mb-4 size-12 text-muted-foreground" />
                            <h3 className="text-lg font-medium">
                                No upstreams configured
                            </h3>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Add an upstream repository to start proxying
                                packages.
                            </p>
                        </div>
                    ) : (
                        <div className="rounded-lg border">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="px-4 py-3 text-left font-medium">
                                            Name
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            Ecosystem
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            URL
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            Auth
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            Priority
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
                                    {upstreams.map((upstream) => (
                                        <tr
                                            key={upstream.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="px-4 py-3 font-medium">
                                                {upstream.name}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant="outline">
                                                    {
                                                        ECOSYSTEM_LABELS[
                                                            upstream.ecosystem
                                                        ]
                                                    }
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                                                {upstream.upstream_url}
                                            </td>
                                            <td className="px-4 py-3">
                                                <AuthBadge
                                                    type={upstream.auth_type}
                                                />
                                            </td>
                                            <td className="px-4 py-3">
                                                {upstream.sort_order}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge
                                                    variant={
                                                        upstream.enabled
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {upstream.enabled
                                                        ? 'Enabled'
                                                        : 'Disabled'}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={`/proxy/upstreams/${upstream.id}/edit`}
                                                        >
                                                            <Pencil className="size-3" />
                                                        </Link>
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            router.delete(
                                                                `/proxy/upstreams/${upstream.id}`,
                                                            )
                                                        }
                                                    >
                                                        <Trash2 className="size-3" />
                                                    </Button>
                                                </div>
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

function ProxyInstructionsDialog({
    proxyUrl,
    authType,
}: {
    proxyUrl: string;
    authType: ProxySetting['auth_type'];
}) {
    const composerConfig = JSON.stringify(
        {
            repositories: [
                {
                    type: 'composer',
                    url: proxyUrl,
                },
            ],
        },
        null,
        4,
    );

    const composerCliCommand = `composer config repositories.proxy composer ${proxyUrl}`;
    const host = new URL(proxyUrl).host;
    const npmRegistryUrl = `${proxyUrl}/npm/`;
    const npmrcAuthKey = `//${host}${new URL(npmRegistryUrl).pathname}`;
    const npmrcLines = [`registry=${npmRegistryUrl}`];

    if (authType === 'basic') {
        npmrcLines.push(
            `${npmrcAuthKey}:username=your-username`,
            `${npmrcAuthKey}:_password=BASE64_OF_YOUR_PASSWORD`,
        );
    }

    if (authType === 'token') {
        npmrcLines.push(`${npmrcAuthKey}:_authToken=your-token`);
    }

    const npmrc = npmrcLines.join('\n');

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <BookOpen className="mr-2 size-4" />
                    Instructions
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Proxy Setup Instructions</DialogTitle>
                    <DialogDescription>
                        Configure Composer or npm to use this proxy.
                    </DialogDescription>
                </DialogHeader>
                <div className="max-h-[70vh] space-y-5 overflow-y-auto pr-1">
                    <h3 className="text-base font-semibold">Composer</h3>
                    <section className="space-y-2">
                        <h4 className="text-sm font-medium">
                            1. Add to composer.json
                        </h4>
                        <CodeBlock code={composerConfig} />
                    </section>

                    <section className="space-y-2">
                        <h4 className="text-sm font-medium">Or use the CLI</h4>
                        <CodeBlock code={composerCliCommand} />
                    </section>

                    {authType === 'basic' && (
                        <section className="space-y-2">
                            <h4 className="text-sm font-medium">
                                2. Configure authentication
                            </h4>
                            <p className="text-xs text-muted-foreground">
                                This proxy requires HTTP Basic authentication.
                            </p>
                            <CodeBlock
                                code={`composer config http-basic.${host} your-username your-password`}
                            />
                        </section>
                    )}

                    {authType === 'token' && (
                        <section className="space-y-2">
                            <h4 className="text-sm font-medium">
                                2. Configure authentication
                            </h4>
                            <p className="text-xs text-muted-foreground">
                                This proxy requires a bearer token.
                            </p>
                            <CodeBlock
                                code={`composer config bearer.${host} your-token`}
                            />
                        </section>
                    )}

                    <section className="space-y-2">
                        <h4 className="text-sm font-medium">
                            Proxy metadata endpoint
                        </h4>
                        <CodeBlock code={`${proxyUrl}/packages.json`} />
                    </section>

                    <h3 className="text-base font-semibold">npm</h3>
                    <section className="space-y-2">
                        <h4 className="text-sm font-medium">
                            Add to your project&apos;s .npmrc
                        </h4>
                        <p className="text-xs text-muted-foreground">
                            Works with npm, pnpm, Bun and Yarn Classic. Yarn
                            Berry reads <code>npmRegistryServer</code> in
                            .yarnrc.yml instead.
                        </p>
                        <CodeBlock code={npmrc} />
                    </section>
                    <section className="space-y-2">
                        <h4 className="text-sm font-medium">Or use the CLI</h4>
                        <CodeBlock
                            code={`npm config set registry ${npmRegistryUrl}`}
                        />
                    </section>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function CodeBlock({ code }: { code: string }) {
    const [copied, setCopied] = useState(false);

    function handleCopy() {
        navigator.clipboard.writeText(code);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }

    return (
        <div className="group relative">
            <pre className="overflow-x-auto rounded-lg border bg-muted/50 p-3 text-xs">
                <code>{code}</code>
            </pre>
            <Button
                variant="outline"
                size="sm"
                className="absolute top-2 right-2 opacity-0 transition-opacity group-hover:opacity-100"
                onClick={handleCopy}
            >
                {copied ? 'Copied!' : <Copy className="size-3" />}
            </Button>
        </div>
    );
}

function AuthBadge({ type }: { type: ProxyUpstream['auth_type'] }) {
    const config = {
        none: { label: 'Public', variant: 'secondary' as const },
        basic: { label: 'Basic Auth', variant: 'default' as const },
        token: { label: 'Token', variant: 'default' as const },
    };
    const { label, variant } = config[type];

    return <Badge variant={variant}>{label}</Badge>;
}

ProxyEdit.layout = {
    breadcrumbs: [
        {
            title: 'Proxy',
            href: '/proxy',
        },
        {
            title: 'Settings',
            href: '#',
        },
    ],
};
