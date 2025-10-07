import React, { useState } from 'react';
import DashboardLayout from '../../layouts/DashboardLayout';
import { Head, usePage, router } from '@inertiajs/react';

interface Product {
  id: number;
  name: string;
  price: number;
  pivot: {
    quantity: number;
    price: number;
    beneficiary_number?: string;
  };
}

interface Order {
  id: number;
  total: number;
  status: string;
  created_at: string;
  network?: string;
  beneficiary_number?: string;
  products: Product[];
}

interface OrdersPageProps {
  orders: Order[];
  auth: any;
  searchOrderId: string;
  searchBeneficiaryNumber: string;
  [key: string]: any;
}

export default function OrdersPage() {
  const { orders, auth, searchOrderId, searchBeneficiaryNumber } = usePage<OrdersPageProps>().props;
  const [expandedOrder, setExpandedOrder] = useState<number | null>(null);
  const [networkFilter, setNetworkFilter] = useState('');
  const [orderIdSearch, setOrderIdSearch] = useState(searchOrderId);
  const [beneficiarySearch, setBeneficiarySearch] = useState(searchBeneficiaryNumber);

  // Extract unique networks for filter dropdown
  const networks = Array.from(new Set(orders.map(o => o.network).filter(Boolean)));

  const filteredOrders = networkFilter
    ? orders.filter(order => order.network === networkFilter)
    : orders;

  const handleExpand = (orderId: number) => {
    setExpandedOrder(expandedOrder === orderId ? null : orderId);
  };



  const getNetworkColor = (network?: string) => {
    if (!network) return '';
    if (network.toLowerCase() === 'telecel') return 'bg-red-500';
    if (network.toLowerCase() === 'mtn') return 'bg-yellow-500';
    if (network.toLowerCase() === 'at data (instant)' || network.toLowerCase() === 'at (big packages)') return 'bg-blue-500';
    return '';
  };

  return (
    <DashboardLayout user={auth?.user} header={<h2 className="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">My Orders</h2>}>
      <Head title="Orders" />
      <div className="py-8 max-w-4xl mx-auto">
        {/* Search and Filter Section */}
        <div className="mb-6">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Search by Order ID</label>
              <input
                type="text"
                className="w-full border rounded-lg px-3 py-2 dark:bg-gray-800 dark:border-gray-600 dark:text-white"
                placeholder="Enter order ID"
                value={orderIdSearch}
                onChange={e => {
                  setOrderIdSearch(e.target.value);
                  router.get(route('dashboard.orders'), {
                    order_id: e.target.value,
                    beneficiary_number: beneficiarySearch
                  }, { preserveState: true, replace: true });
                }}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Search by Beneficiary Number</label>
              <input
                type="text"
                className="w-full border rounded-lg px-3 py-2 dark:bg-gray-800 dark:border-gray-600 dark:text-white"
                placeholder="Enter beneficiary number"
                value={beneficiarySearch}
                onChange={e => {
                  setBeneficiarySearch(e.target.value);
                  router.get(route('dashboard.orders'), {
                    order_id: orderIdSearch,
                    beneficiary_number: e.target.value
                  }, { preserveState: true, replace: true });
                }}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Filter by Network</label>
              <select
                className="w-full border rounded-lg px-3 py-2 dark:bg-gray-800 dark:border-gray-600 dark:text-white"
                value={networkFilter}
                onChange={e => setNetworkFilter(e.target.value)}
              >
                <option value="">All Networks</option>
                {networks.map(network => (
                  <option key={network} value={network}>{network}</option>
                ))}
              </select>
            </div>
          </div>
        </div>
        {filteredOrders.length === 0 ? (
          <div>No orders found.</div>
        ) : (
          <div className="overflow-x-auto rounded-lg shadow">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800">
              <thead>
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Network</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                  <th className="px-4 py-3"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                {filteredOrders.map(order => (
                  <React.Fragment key={order.id}>
                    <tr
                      className={`hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer transition-colors duration-200 ${getNetworkColor(order.network)}`}
                      onClick={() => handleExpand(order.id)}
                    >
                      <td className="px-4 py-3 font-bold">{order.id}</td>
                      <td className="px-4 py-3">{new Date(order.created_at).toLocaleString()}</td>
                      <td className="px-4 py-3">{order.network || '-'}</td>
                      <td className="px-4 py-3">
                        <span className={`px-2 py-1 rounded text-xs font-semibold ${order.status === 'completed' ? 'bg-green-100 text-green-700' : order.status === 'pending' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-200 text-gray-700'}`}>{order.status}</span>
                      </td>
                      <td className="px-4 py-3">${order.total}</td>
                      <td className="px-4 py-3 text-right">
                        <button className="text-blue-600 hover:underline focus:outline-none" aria-label="Expand order details">
                          {expandedOrder === order.id ? 'Hide' : 'Details'}
                        </button>
                      </td>
                    </tr>
                    <tr>
                      <td colSpan={6} className={`transition-all duration-300 ease-in-out overflow-hidden p-0 ${expandedOrder === order.id ? 'h-auto' : 'h-0'}`}
                        style={{ display: expandedOrder === order.id ? 'table-cell' : 'none' }}>
                        <div className="flex flex-col gap-2 px-4 py-4">
                          <div className="font-semibold">Order Details</div>
                          <div>Status: <span className="font-semibold">{order.status}</span></div>
                          <div>Total: <span className="font-semibold">${order.total}</span></div>
                          <div>Products:</div>
                          <ul className="ml-4 list-disc space-y-2">
                            {order.products.map(product => (
                              <li key={product.id} className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-1">
                                <span>{product.name} - ${product.pivot.price}</span>
                                <span className="text-sm text-gray-600 dark:text-gray-400">
                                  Beneficiary: {product.pivot.beneficiary_number || '-'}
                                </span>
                              </li>
                            ))}
                          </ul>
                        </div>
                      </td>
                    </tr>
                  </React.Fragment>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </DashboardLayout>
  );
}