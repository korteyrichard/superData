import { Head, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/admin-layout';
import { PageProps } from '@/types';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';

interface Commission {
    id: number;
    amount: number;
    status: string;
    created_at: string;
    available_at?: string;
    agent: {
        name: string;
        email: string;
    };
    order: {
        id: number;
        total: number;
    };
}

interface AdminCommissionsProps extends PageProps {
    commissions: {
        data: Commission[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filterStatus: string;
    agents: Array<{ id: number; name: string; email: string }>;
    totalAvailableCommissions: number;
}

export default function AdminCommissions({ auth, commissions, filterStatus, totalAvailableCommissions }: AdminCommissionsProps) {
    const { post, processing } = useForm();

    const makeAvailable = (commissionId: number) => {
        post(`/admin/commissions/${commissionId}/available`);
    };

    const getStatusBadge = (status: string) => {
        const colors = {
            pending: 'bg-yellow-100 text-yellow-800',
            available: 'bg-green-100 text-green-800',
            withdrawn: 'bg-gray-100 text-gray-800'
        };

        return (
            <Badge className={colors[status as keyof typeof colors]}>
                {status.charAt(0).toUpperCase() + status.slice(1)}
            </Badge>
        );
    };

    return (
        <AdminLayout user={auth.user} header="Commission Management">
            <Head title="Admin - Commissions" />

            <div className="space-y-6">
                {/* Stats */}
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">Total Commissions</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{commissions.total}</div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">Available</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {commissions.data.filter(c => c.status === 'available').length}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">Total Available Amount</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-green-600">
                                GHS {Number(totalAvailableCommissions || 0).toFixed(2)}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">Available</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {commissions.data.filter(c => c.status === 'available').length}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">Total Amount</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                GHS {commissions.data.reduce((sum, c) => sum + Number(c.amount), 0).toFixed(2)}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Commissions Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Agent Commissions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead>
                                    <tr className="border-b">
                                        <th className="text-left p-2">Agent</th>
                                        <th className="text-left p-2">Order ID</th>
                                        <th className="text-left p-2">Commission</th>
                                        <th className="text-left p-2">Status</th>
                                        <th className="text-left p-2">Created</th>
                                        <th className="text-left p-2">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {commissions.data.map((commission) => (
                                        <tr key={commission.id} className="border-b">
                                            <td className="p-2">
                                                <div>
                                                    <div className="font-medium">{commission.agent.name}</div>
                                                    <div className="text-sm text-gray-500">{commission.agent.email}</div>
                                                </div>
                                            </td>
                                            <td className="p-2">
                                                <div className="font-mono text-sm">#{commission.order.id}</div>
                                                <div className="text-xs text-gray-500">
                                                    Order: GHS {commission.order.total}
                                                </div>
                                            </td>
                                            <td className="p-2 font-semibold">GHS {commission.amount}</td>
                                            <td className="p-2">{getStatusBadge(commission.status)}</td>
                                            <td className="p-2 text-sm text-gray-500">
                                                {new Date(commission.created_at).toLocaleDateString()}
                                            </td>
                                            <td className="p-2">
                                            {commission.status === 'available' && (
                                                <span className="text-sm text-green-600">Available for withdrawal</span>
                                            )}
                                                {commission.status === 'withdrawn' && (
                                                    <span className="text-sm text-gray-500">Withdrawn</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {commissions.data.length === 0 && (
                            <div className="text-center py-8">
                                <p className="text-gray-500">No commissions found</p>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}