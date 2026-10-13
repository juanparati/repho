import { Form, Head } from '@inertiajs/react';
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
import { ECOSYSTEM_LABELS } from '@/types/package';
import type { Credential, Package } from '@/types/package';

type PageProps = {
    package: Pick<
        Package,
        | 'id'
        | 'name'
        | 'ecosystem'
        | 'repository_url'
        | 'type'
        | 'download_dists'
    > & {
        credential_id: number | null;
    };
    credentials: Credential[];
};

export default function PackagesEdit({ package: pkg, credentials }: PageProps) {
    return (
        <>
            <Head title={`Edit ${pkg.name}`} />

            <div className="mx-auto max-w-2xl space-y-6 p-4">
                <Heading
                    title={`Edit ${pkg.name}`}
                    description="Update package settings"
                />

                <Form
                    action={`/packages/${pkg.id}`}
                    method="put"
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label>Ecosystem</Label>
                                <div>
                                    <Badge variant="outline">
                                        {ECOSYSTEM_LABELS[pkg.ecosystem]}
                                    </Badge>
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="repository_url">
                                    Repository URL
                                </Label>
                                <Input
                                    id="repository_url"
                                    name="repository_url"
                                    type="url"
                                    defaultValue={pkg.repository_url}
                                    required
                                />
                                <InputError message={errors.repository_url} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="type">Source Type</Label>
                                <Select name="type" defaultValue={pkg.type}>
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
                                <Select
                                    name="credential_id"
                                    defaultValue={
                                        pkg.credential_id
                                            ? String(pkg.credential_id)
                                            : undefined
                                    }
                                >
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

                            {pkg.ecosystem === 'composer' && (
                                <label className="flex items-center gap-2">
                                    <Checkbox
                                        name="download_dists"
                                        value="1"
                                        defaultChecked={pkg.download_dists}
                                    />
                                    <span className="text-sm">
                                        Download dist archives on sync
                                    </span>
                                </label>
                            )}

                            <Button type="submit" disabled={processing}>
                                {processing ? 'Saving...' : 'Save Changes'}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

PackagesEdit.layout = {
    breadcrumbs: [
        {
            title: 'Packages',
            href: '/packages',
        },
        {
            title: 'Edit Package',
            href: '#',
        },
    ],
};
