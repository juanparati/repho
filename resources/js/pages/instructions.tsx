import { Head, usePage } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { Ecosystem, Repository } from '@/types/package';

type PageProps = {
    repositories: Pick<Repository, 'id' | 'name' | 'slug' | 'auth_type'>[];
};

export default function Instructions({ repositories }: PageProps) {
    const { appUrl } = usePage<{ appUrl: string }>().props;
    const baseUrl = appUrl.replace(/\/+$/, '');

    const [selectedSlug, setSelectedSlug] = useState(
        repositories[0]?.slug ?? 'default',
    );
    const [ecosystem, setEcosystem] = useState<Ecosystem>('composer');

    const selectedRepo = repositories.find((r) => r.slug === selectedSlug);
    const repoUrl = `${baseUrl}/repo/${selectedSlug}`;
    const npmUrl = `${baseUrl}/npm/${selectedSlug}/`;

    const composerConfig = JSON.stringify(
        {
            repositories: [
                {
                    type: 'composer',
                    url: repoUrl,
                },
            ],
        },
        null,
        4,
    );

    const composerCliCommand = `composer config repositories.${selectedSlug} composer ${repoUrl}`;

    return (
        <>
            <Head title="Instructions" />

            <div className="mx-auto max-w-3xl space-y-8 p-4">
                <Heading
                    title="Instructions"
                    description="How to use this package repository with Composer or npm"
                />

                <ToggleGroup
                    type="single"
                    variant="outline"
                    value={ecosystem}
                    onValueChange={(value) => {
                        if (value) {
                            setEcosystem(value as Ecosystem);
                        }
                    }}
                >
                    <ToggleGroupItem value="composer">Composer</ToggleGroupItem>
                    <ToggleGroupItem value="npm">npm</ToggleGroupItem>
                </ToggleGroup>

                {repositories.length > 1 && (
                    <div className="grid gap-2">
                        <label className="text-sm font-medium">
                            Select Repository
                        </label>
                        <Select
                            value={selectedSlug}
                            onValueChange={setSelectedSlug}
                        >
                            <SelectTrigger className="w-full max-w-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {repositories.map((repo) => (
                                    <SelectItem key={repo.id} value={repo.slug}>
                                        {repo.name} ({repo.slug})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )}

                {ecosystem === 'composer' && (
                    <>
                        <section className="space-y-3">
                            <h3 className="text-lg font-medium">
                                1. Add the repository to your project
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                Add the following to your project's{' '}
                                <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                    composer.json
                                </code>{' '}
                                file:
                            </p>
                            <CodeBlock code={composerConfig} />
                        </section>

                        <section className="space-y-3">
                            <h3 className="text-lg font-medium">
                                Or use the Composer CLI
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                Run this command in your project directory:
                            </p>
                            <CodeBlock code={composerCliCommand} />
                        </section>

                        {selectedRepo && selectedRepo.auth_type !== 'none' && (
                            <section className="space-y-3">
                                <h3 className="text-lg font-medium">
                                    2. Configure authentication
                                </h3>
                                {selectedRepo.auth_type === 'basic' && (
                                    <>
                                        <p className="text-sm text-muted-foreground">
                                            This repository requires HTTP Basic
                                            authentication. Run:
                                        </p>
                                        <CodeBlock
                                            code={`composer config http-basic.${new URL(repoUrl).host} your-username your-password`}
                                        />
                                    </>
                                )}
                                {selectedRepo.auth_type === 'token' && (
                                    <>
                                        <p className="text-sm text-muted-foreground">
                                            This repository requires a bearer
                                            token. Run:
                                        </p>
                                        <CodeBlock
                                            code={`composer config bearer.${new URL(repoUrl).host} your-token`}
                                        />
                                    </>
                                )}
                            </section>
                        )}

                        <section className="space-y-3">
                            <h3 className="text-lg font-medium">
                                {selectedRepo?.auth_type !== 'none' ? '3' : '2'}
                                . Require a package
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                Once the repository is configured, you can
                                require packages as usual:
                            </p>
                            <CodeBlock code="composer require vendor/package-name" />
                        </section>

                        <section className="space-y-3">
                            <h3 className="text-lg font-medium">
                                Repository URL
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                Your repository metadata endpoint:
                            </p>
                            <CodeBlock code={`${repoUrl}/packages.json`} />
                        </section>
                    </>
                )}

                {ecosystem === 'npm' && (
                    <NpmInstructions
                        npmUrl={npmUrl}
                        authType={selectedRepo?.auth_type ?? 'none'}
                    />
                )}
            </div>
        </>
    );
}

function NpmInstructions({
    npmUrl,
    authType,
}: {
    npmUrl: string;
    authType: Repository['auth_type'];
}) {
    const url = new URL(npmUrl);
    const authKey = `//${url.host}${url.pathname}`;
    const npmrcLines = [`@your-scope:registry=${npmUrl}`];

    if (authType === 'basic') {
        npmrcLines.push(
            `${authKey}:username=your-username`,
            `${authKey}:_password=BASE64_OF_YOUR_PASSWORD`,
        );
    }

    if (authType === 'token') {
        npmrcLines.push(`${authKey}:_authToken=your-token`);
    }

    const yarnBerry = [
        'npmScopes:',
        '  your-scope:',
        `    npmRegistryServer: "${npmUrl}"`,
        ...(authType === 'token'
            ? ['    npmAuthToken: "your-token"']
            : authType === 'basic'
              ? ['    npmAuthIdent: "your-username:your-password"']
              : []),
    ].join('\n');

    return (
        <>
            <section className="space-y-3">
                <h3 className="text-lg font-medium">
                    1. Point your scope at this registry
                </h3>
                <p className="text-sm text-muted-foreground">
                    Add the following to your project&apos;s{' '}
                    <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                        .npmrc
                    </code>{' '}
                    (npm, pnpm, Bun and Yarn Classic all read it). Replace{' '}
                    <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                        @your-scope
                    </code>{' '}
                    with your packages&apos; scope, or use{' '}
                    <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                        registry=
                    </code>{' '}
                    for unscoped packages.
                </p>
                <CodeBlock code={npmrcLines.join('\n')} />
            </section>

            <section className="space-y-3">
                <h3 className="text-lg font-medium">Or use the npm CLI</h3>
                <CodeBlock
                    code={`npm config set @your-scope:registry ${npmUrl}`}
                />
            </section>

            <section className="space-y-3">
                <h3 className="text-lg font-medium">
                    Yarn Berry (.yarnrc.yml)
                </h3>
                <CodeBlock code={yarnBerry} />
            </section>

            {authType === 'basic' && (
                <section className="space-y-3">
                    <h3 className="text-lg font-medium">
                        2. Configure authentication
                    </h3>
                    <p className="text-sm text-muted-foreground">
                        This repository requires HTTP Basic authentication. npm
                        expects the password base64-encoded in{' '}
                        <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                            _password
                        </code>
                        :
                    </p>
                    <CodeBlock code={`echo -n 'your-password' | base64`} />
                </section>
            )}

            {authType === 'token' && (
                <section className="space-y-3">
                    <h3 className="text-lg font-medium">
                        2. Configure authentication
                    </h3>
                    <p className="text-sm text-muted-foreground">
                        This repository requires a bearer token, sent by npm
                        from the{' '}
                        <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                            _authToken
                        </code>{' '}
                        entry above.
                    </p>
                </section>
            )}

            <section className="space-y-3">
                <h3 className="text-lg font-medium">
                    {authType !== 'none' ? '3' : '2'}. Install a package
                </h3>
                <CodeBlock code="npm install @your-scope/package-name" />
            </section>

            <section className="space-y-3">
                <h3 className="text-lg font-medium">Registry URL</h3>
                <CodeBlock code={npmUrl} />
            </section>
        </>
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
            <pre className="overflow-x-auto rounded-lg border bg-muted/50 p-4 text-sm">
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

Instructions.layout = {
    breadcrumbs: [
        {
            title: 'Instructions',
            href: '/instructions',
        },
    ],
};
