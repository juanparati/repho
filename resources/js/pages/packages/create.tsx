import { Form, Head } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Credential, Ecosystem, Repository } from '@/types/package';

type PageProps = {
    credentials: Credential[];
    repositories: Pick<Repository, 'id' | 'name' | 'slug'>[];
};

export default function PackagesCreate({
    credentials,
    repositories,
}: PageProps) {
    const [selectedRepoIds, setSelectedRepoIds] = useState<number[]>([]);
    const [ecosystem, setEcosystem] = useState<Ecosystem>('composer');

    const availableRepos = repositories.filter(
        (r) => !selectedRepoIds.includes(r.id),
    );

    const addRepository = (id: string) => {
        setSelectedRepoIds((prev) => [...prev, Number(id)]);
    };

    const removeRepository = (id: number) => {
        setSelectedRepoIds((prev) => prev.filter((rid) => rid !== id));
    };

    return (
        <>
            <Head title="Add Package" />

            <div className="mx-auto max-w-2xl space-y-6 p-4">
                <Heading
                    title="Add Package"
                    description="Add a new package from a Git repository"
                />

                <Form action="/packages" method="post" className="space-y-6">
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="ecosystem">Ecosystem</Label>
                                <Select
                                    name="ecosystem"
                                    defaultValue="composer"
                                    onValueChange={(value) =>
                                        setEcosystem(value as Ecosystem)
                                    }
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="composer">
                                            Composer
                                        </SelectItem>
                                        <SelectItem value="npm">npm</SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.ecosystem} />
                            </div>

                            {ecosystem === 'npm' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Package name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        placeholder="@scope/package (optional)"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Leave blank to read it from the
                                        repository&apos;s package.json on the
                                        default branch. If you enter one, it
                                        must match that name or the first sync
                                        fails.
                                    </p>
                                    <InputError message={errors.name} />
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="repository_url">
                                    Repository URL
                                </Label>
                                <Input
                                    id="repository_url"
                                    name="repository_url"
                                    type="url"
                                    placeholder="https://github.com/vendor/package"
                                    required
                                />
                                <InputError message={errors.repository_url} />
                            </div>

                            {ecosystem === 'npm' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="subdirectory">
                                        Subdirectory
                                    </Label>
                                    <Input
                                        id="subdirectory"
                                        name="subdirectory"
                                        placeholder="packages/my-package (optional)"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        For monorepos: the folder inside the
                                        repository that holds the package.json
                                        to publish. Leave blank when it is at
                                        the repository root.
                                    </p>
                                    <InputError message={errors.subdirectory} />
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="type">Source Type</Label>
                                <Select name="type" defaultValue="github">
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="Select type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="github">
                                            GitHub
                                        </SelectItem>
                                        <SelectItem value="gitlab">
                                            GitLab
                                        </SelectItem>
                                        <SelectItem value="git">Git</SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.type} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="credential_id">
                                    Credential (optional)
                                </Label>
                                <Select name="credential_id">
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="No credential" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {credentials.map((cred) => (
                                            <SelectItem
                                                key={cred.id}
                                                value={String(cred.id)}
                                            >
                                                {cred.name} ({cred.type})
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.credential_id} />
                            </div>

                            <div className="grid gap-2">
                                <Label>Repositories</Label>
                                {selectedRepoIds.length > 0 && (
                                    <div className="flex flex-wrap gap-1.5">
                                        {selectedRepoIds.map((id) => {
                                            const repo = repositories.find(
                                                (r) => r.id === id,
                                            );

                                            if (!repo) {
                                                return null;
                                            }

                                            return (
                                                <Badge
                                                    key={id}
                                                    variant="secondary"
                                                    className="gap-1 pr-1"
                                                >
                                                    {repo.name}
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            removeRepository(id)
                                                        }
                                                        className="rounded-full p-0.5 hover:bg-muted"
                                                    >
                                                        <X className="size-3" />
                                                    </button>
                                                </Badge>
                                            );
                                        })}
                                    </div>
                                )}
                                {availableRepos.length > 0 && (
                                    <Select
                                        key={selectedRepoIds.length}
                                        onValueChange={addRepository}
                                    >
                                        <SelectTrigger className="w-full">
                                            <SelectValue placeholder="Select a repository" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {availableRepos.map((repo) => (
                                                <SelectItem
                                                    key={repo.id}
                                                    value={String(repo.id)}
                                                >
                                                    {repo.name}{' '}
                                                    <span className="text-muted-foreground">
                                                        ({repo.slug})
                                                    </span>
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                                {selectedRepoIds.map((id) => (
                                    <input
                                        key={id}
                                        type="hidden"
                                        name="repository_ids[]"
                                        value={id}
                                    />
                                ))}
                                <InputError message={errors.repository_ids} />
                            </div>

                            {ecosystem === 'composer' ? (
                                <label className="flex items-center gap-2">
                                    <Checkbox name="download_dists" value="1" />
                                    <span className="text-sm">
                                        Download dist archives on sync
                                    </span>
                                </label>
                            ) : (
                                <p className="text-xs text-muted-foreground">
                                    npm tarballs are always built and stored on
                                    sync, so their integrity hash can be
                                    published.
                                </p>
                            )}

                            <Button type="submit" disabled={processing}>
                                {processing
                                    ? 'Adding & Syncing...'
                                    : 'Add Package'}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

PackagesCreate.layout = {
    breadcrumbs: [
        {
            title: 'Packages',
            href: '/packages',
        },
        {
            title: 'Add Package',
            href: '/packages/create',
        },
    ],
};
