import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import { AdminLayout } from '@/layouts/admin-layout';
import { PageProps } from '@/types';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Settings {
  how_to_track_orders_youtube_link: string;
  how_to_verify_topup_youtube_link: string;
  minimum_withdrawal: string;
  agent_fee: string;
  referral_commission: string;
  order_based_referral_commission: string;
}

interface AdminSettingsProps extends PageProps {
  settings: Settings;
}

export default function AdminSettings({ auth, settings }: AdminSettingsProps) {
  const { data, setData, post, processing, errors } = useForm({
    how_to_track_orders_youtube_link: settings.how_to_track_orders_youtube_link || '',
    how_to_verify_topup_youtube_link: settings.how_to_verify_topup_youtube_link || '',
    minimum_withdrawal: settings.minimum_withdrawal || '10.00',
    agent_fee: settings.agent_fee || '0.00',
    referral_commission: settings.referral_commission || '0.50',
    order_based_referral_commission: settings.order_based_referral_commission || '0.50',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post(route('admin.settings.update'));
  };

  return (
    <AdminLayout
      user={auth.user}
      header={
        <h2 className="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
          Admin Settings
        </h2>
      }
    >
      <Head title="Admin Settings" />

      <div className="py-12">
        <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
          <Card>
            <CardHeader>
              <CardTitle className="text-2xl font-bold">System Settings</CardTitle>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleSubmit} className="space-y-6">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  {/* YouTube Links Section */}
                  <div className="space-y-4">
                    <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b pb-2">
                      YouTube Help Videos
                    </h3>
                    <div>
                      <Label htmlFor="how_to_track_orders_youtube_link">
                        How to Track Orders (Shop Page)
                      </Label>
                      <Input
                        id="how_to_track_orders_youtube_link"
                        type="url"
                        placeholder="https://youtube.com/watch?v=..."
                        value={data.how_to_track_orders_youtube_link}
                        onChange={(e) => setData('how_to_track_orders_youtube_link', e.target.value)}
                        className="mt-1"
                      />
                      {errors.how_to_track_orders_youtube_link && (
                        <p className="text-red-500 text-sm mt-1">{errors.how_to_track_orders_youtube_link}</p>
                      )}
                    </div>
                    <div>
                      <Label htmlFor="how_to_verify_topup_youtube_link">
                        How to Verify Top Up (Wallet Page)
                      </Label>
                      <Input
                        id="how_to_verify_topup_youtube_link"
                        type="url"
                        placeholder="https://youtube.com/watch?v=..."
                        value={data.how_to_verify_topup_youtube_link}
                        onChange={(e) => setData('how_to_verify_topup_youtube_link', e.target.value)}
                        className="mt-1"
                      />
                      {errors.how_to_verify_topup_youtube_link && (
                        <p className="text-red-500 text-sm mt-1">{errors.how_to_verify_topup_youtube_link}</p>
                      )}
                    </div>
                  </div>

                  {/* Financial Settings Section */}
                  <div className="space-y-4">
                    <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b pb-2">
                      Financial Settings
                    </h3>
                    <div>
                      <Label htmlFor="minimum_withdrawal">
                        Minimum Withdrawal Amount (GHS)
                      </Label>
                      <Input
                        id="minimum_withdrawal"
                        type="number"
                        step="0.01"
                        min="0"
                        placeholder="10.00"
                        value={data.minimum_withdrawal}
                        onChange={(e) => setData('minimum_withdrawal', e.target.value)}
                        className="mt-1"
                        required
                      />
                      {errors.minimum_withdrawal && (
                        <p className="text-red-500 text-sm mt-1">{errors.minimum_withdrawal}</p>
                      )}
                    </div>
                    <div>
                      <Label htmlFor="agent_fee">
                        Agent Fee (GHS)
                      </Label>
                      <Input
                        id="agent_fee"
                        type="number"
                        step="0.01"
                        min="0"
                        placeholder="0.00"
                        value={data.agent_fee}
                        onChange={(e) => setData('agent_fee', e.target.value)}
                        className="mt-1"
                        required
                      />
                      {errors.agent_fee && (
                        <p className="text-red-500 text-sm mt-1">{errors.agent_fee}</p>
                      )}
                    </div>
                    <div>
                      <Label htmlFor="referral_commission">
                        Dealer Upgrade Referral Commission (GHS)
                      </Label>
                      <Input
                        id="referral_commission"
                        type="number"
                        step="0.01"
                        min="0"
                        placeholder="0.50"
                        value={data.referral_commission}
                        onChange={(e) => setData('referral_commission', e.target.value)}
                        className="mt-1"
                        required
                      />
                      <p className="text-xs text-gray-500 mt-1">Commission paid when referred user becomes a dealer</p>
                      {errors.referral_commission && (
                        <p className="text-red-500 text-sm mt-1">{errors.referral_commission}</p>
                      )}
                    </div>
                    <div>
                      <Label htmlFor="order_based_referral_commission">
                        Order-Based Referral Commission (GHS)
                      </Label>
                      <Input
                        id="order_based_referral_commission"
                        type="number"
                        step="0.01"
                        min="0"
                        placeholder="0.50"
                        value={data.order_based_referral_commission}
                        onChange={(e) => setData('order_based_referral_commission', e.target.value)}
                        className="mt-1"
                        required
                      />
                      <p className="text-xs text-gray-500 mt-1">Commission paid for orders 5GB+ made by referred users</p>
                      {errors.order_based_referral_commission && (
                        <p className="text-red-500 text-sm mt-1">{errors.order_based_referral_commission}</p>
                      )}
                    </div>
                  </div>
                </div>

                <div className="flex justify-end pt-6 border-t">
                  <Button
                    type="submit"
                    disabled={processing}
                    className="px-6 py-2"
                  >
                    {processing ? 'Updating...' : 'Update Settings'}
                  </Button>
                </div>
              </form>
            </CardContent>
          </Card>
        </div>
      </div>
    </AdminLayout>
  );
}