import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import type { ProxyUpstream } from '@/types/package';

type PageProps = {
    upstream: ProxyUpstream;
};

export default function UpstreamsEdit({ upstream }: PageProps) {
    const [authType, setAuthType] = useState(upstream.auth_type);
    const [enabled, setEnabled] = useState(upstream.enabled);

    return (
        <>
            <Head title={`Edit ${upstream.name}`} />

            <div className="mx-auto max-w-2xl space-y-6 p-4">
                <Heading
                    title={`Edit ${upstream.name}`}
                    description="Update upstream repository settings."
                />

                <Form
                    action={`/proxy/upstreams/${upstream.id}`}
                    method="put"
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="enabled"
                                value={enabled ? '1' : '0'}
                            />
                            <label className="flex items-center gap-2">
                                <Checkbox
                                    checked={enabled}
                                    onCheckedChange={(checked) =>
                                        setEnabled(checked === true)
                                    }
                                />
                                <span className="text-sm font-medium">
                                    Enabled
                                </span>
                            </label>

                            <div className="grid gap-2">
                                <Label htmlFor="ecosystem">Ecosystem</Label>
                                <Select
                                    name="ecosystem"
                                    defaultValue={upstream.ecosystem}
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

                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={upstream.name}
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="upstream_url">
                                    Upstream URL
                                </Label>
                                <Input
                                    id="upstream_url"
                                    name="upstream_url"
                                    type="url"
                                    defaultValue={upstream.upstream_url}
                                    required
                                />
                                <InputError message={errors.upstream_url} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="sort_order">
                                    Priority (lower = higher priority)
                                </Label>
                                <Input
                                    id="sort_order"
                                    name="sort_order"
                                    type="number"
                                    defaultValue={upstream.sort_order}
                                    min={0}
                                />
                                <InputError message={errors.sort_order} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="auth_type">
                                    Authentication
                                </Label>
                                <Select
                                    name="auth_type"
                                    defaultValue={upstream.auth_type}
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
                                                upstream.auth_username ?? ''
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
                                    <Label htmlFor="auth_token">Token</Label>
                                    <Input
                                        id="auth_token"
                                        name="auth_token"
                                        type="password"
                                        placeholder="Leave blank to keep current"
                                    />
                                    <InputError message={errors.auth_token} />
                                </div>
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

UpstreamsEdit.layout = {
    breadcrumbs: [
        {
            title: 'Proxy',
            href: '/proxy',
        },
        {
            title: 'Edit Upstream',
            href: '#',
        },
    ],
};
