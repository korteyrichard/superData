import React, { useState } from "react";
import { AdminLayout } from "../../layouts/admin-layout";
import { Button } from "@/components/ui/button";
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Calendar, DollarSign, FileText } from "lucide-react";
import Pagination from '@/components/pagination';

interface Transaction {
  id: number;
  amount: string;
  status: string;
  type: string;
  description: string;
  created_at: string;
  balance_before?: string;
  balance_after?: string;
}

interface PaginatedTransactions {
  data: Transaction[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number;
  to: number;
  links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface User {
  id: number;
  name: string;
  email: string;
  phone?: string;
  role: string;
  wallet_balance?: string;
}

interface PageProps {
  auth: any;
  user: User;
  transactions: PaginatedTransactions;
  filterType: string;
  filterDate: string;
  [key: string]: any;
}

const typeClasses: Record<string, string> = {
  wallet_topup: "bg-blue-100 text-blue-800",
  topup: "bg-blue-100 text-blue-800",
  order_payment: "bg-purple-100 text-purple-800",
  order: "bg-purple-100 text-purple-800",
  agent_fee: "bg-orange-100 text-orange-800",
  refund: "bg-green-100 text-green-800",
  credit: "bg-emerald-100 text-emerald-800",
  debit: "bg-red-100 text-red-800",
};

const statusClasses: Record<string, string> = {
  completed: "bg-green-100 text-green-800",
  pending: "bg-yellow-100 text-yellow-800",
  failed: "bg-red-100 text-red-800",
  cancelled: "bg-gray-100 text-gray-800",
};

export default function UserTransactionsPage() {
  const { auth, user, transactions, filterType: initialType, filterDate: initialDate } = usePage<PageProps>().props;

  const [filterType, setFilterType] = useState(initialType);
  const [filterDate, setFilterDate] = useState(initialDate);

  const applyFilters = (type: string, date: string) => {
    const params: Record<string, string> = {};
    if (type) params.type = type;
    if (date) params.date = date;
    router.get(route('admin.users.transactions', user.id), params, { preserveState: true, replace: true, preserveScroll: true });
  };

  const totalAmount = transactions.data.reduce((sum, t) => sum + parseFloat(t.amount), 0);
  const completedAmount = transactions.data.filter(t => t.status === 'completed').reduce((sum, t) => sum + parseFloat(t.amount), 0);

  return (
    <AdminLayout
      user={auth.user}
      header={
        <div className="flex items-center gap-4">
          <Link href={route('admin.users')}>
            <Button variant="ghost" size="sm">
              <ArrowLeft className="w-4 h-4 mr-2" />
              Back to Users
            </Button>
          </Link>
          <h2 className="font-semibold text-sm text-gray-800 dark:text-gray-200 leading-tight">
            Transaction History - {user.name}
          </h2>
        </div>
      }
    >
      <Head title={`Transactions - ${user.name}`} />

      {/* User Info Card */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6 mb-6">
        <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
          <div>
            <h3 className="text-xs font-medium text-gray-500 dark:text-gray-400">User Name</h3>
            <p className="text-xs font-semibold text-gray-900 dark:text-gray-100">{user.name}</p>
          </div>
          <div>
            <h3 className="text-xs font-medium text-gray-500 dark:text-gray-400">Email</h3>
            <p className="text-xs font-semibold text-gray-900 dark:text-gray-100">{user.email}</p>
          </div>
          <div>
            <h3 className="text-xs font-medium text-gray-500 dark:text-gray-400">Phone</h3>
            <p className="text-xs font-semibold text-gray-900 dark:text-gray-100">{user.phone}</p>
          </div>
          <div>
            <h3 className="text-xs font-medium text-gray-500 dark:text-gray-400">Role</h3>
            <p className="text-xs font-semibold text-gray-900 dark:text-gray-100 capitalize">{user.role}</p>
          </div>
          <div>
            <h3 className="text-xs font-medium text-gray-500 dark:text-gray-400">Wallet Balance</h3>
            <p className="text-xs font-semibold text-gray-900 dark:text-gray-100">₵{user.wallet_balance || '0.00'}</p>
          </div>
        </div>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div className="bg-white rounded-lg p-4 shadow-sm border border-gray-100">
          <div className="flex items-center justify-between mb-2">
            <h3 className="text-xs font-semibold text-gray-600 uppercase tracking-wide">Showing</h3>
            <div className="p-2 bg-blue-50 rounded-lg"><FileText className="w-4 h-4 text-blue-600" /></div>
          </div>
          <p className="text-2xl font-bold text-gray-900">{transactions.total}</p>
        </div>
        <div className="bg-white rounded-lg p-4 shadow-sm border border-gray-100">
          <div className="flex items-center justify-between mb-2">
            <h3 className="text-xs font-semibold text-gray-600 uppercase tracking-wide">Page Amount</h3>
            <div className="p-2 bg-green-50 rounded-lg"><DollarSign className="w-4 h-4 text-green-600" /></div>
          </div>
          <p className="text-2xl font-bold text-gray-900">₵{totalAmount.toFixed(2)}</p>
        </div>
        <div className="bg-white rounded-lg p-4 shadow-sm border border-gray-100">
          <div className="flex items-center justify-between mb-2">
            <h3 className="text-xs font-semibold text-gray-600 uppercase tracking-wide">Completed Amount</h3>
            <div className="p-2 bg-purple-50 rounded-lg"><Calendar className="w-4 h-4 text-purple-600" /></div>
          </div>
          <p className="text-2xl font-bold text-gray-900">₵{completedAmount.toFixed(2)}</p>
        </div>
      </div>

      {/* Filters */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow px-6 py-4 mb-4 flex flex-col sm:flex-row gap-3 sm:items-center">
        <div className="flex items-center gap-2">
          <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Type:</label>
          <select
            className="border rounded px-3 py-2 text-sm w-44 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
            value={filterType}
            onChange={e => setFilterType(e.target.value)}
          >
            <option value="">All Types</option>
            <option value="topup">Wallet Top Ups</option>
            <option value="order">Order Purchases</option>
            <option value="agent_fee">Agent Fees</option>
            <option value="refund">Refunds</option>
            <option value="credit">Admin Credits</option>
            <option value="debit">Admin Debits</option>
          </select>
        </div>
        <div className="flex items-center gap-2">
          <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Date:</label>
          <input
            type="date"
            className="border rounded px-3 py-2 text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
            value={filterDate}
            onChange={e => setFilterDate(e.target.value)}
          />
        </div>
        <button
          onClick={() => applyFilters(filterType, filterDate)}
          className="px-4 py-2 bg-blue-600 text-white rounded text-sm hover:bg-blue-700"
        >
          Search
        </button>
        {(filterType || filterDate) && (
          <button
            onClick={() => { setFilterType(''); setFilterDate(''); applyFilters('', ''); }}
            className="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded text-sm hover:bg-gray-300 dark:hover:bg-gray-600"
          >
            Clear
          </button>
        )}
      </div>

      {/* Transactions Table */}
      <div className="bg-white rounded-lg shadow overflow-hidden">
        <div className="px-6 py-4 border-b border-gray-200">
          <h3 className="text-lg font-medium text-gray-900">Transaction History</h3>
        </div>

        {transactions.data.length > 0 ? (
          <>
            {/* Desktop Table */}
            <div className="hidden md:block overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Balance Before</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Balance After</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {transactions.data.map((transaction) => (
                    <tr key={transaction.id}>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{new Date(transaction.created_at).toLocaleDateString()}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{new Date(transaction.created_at).toLocaleTimeString()}</td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={`px-2 py-1 text-xs font-medium rounded-full ${typeClasses[transaction.type] || 'bg-gray-100 text-gray-800'}`}>
                          {transaction.type.replace('_', ' ')}
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">₵{parseFloat(transaction.amount).toFixed(2)}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{transaction.balance_before ? `₵${parseFloat(transaction.balance_before).toFixed(2)}` : 'N/A'}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{transaction.balance_after ? `₵${parseFloat(transaction.balance_after).toFixed(2)}` : 'N/A'}</td>
                      <td className="px-6 py-4 text-sm text-gray-900 max-w-xs truncate">{transaction.description}</td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={`px-2 py-1 text-xs font-medium rounded-full ${statusClasses[transaction.status] || 'bg-gray-100 text-gray-800'}`}>
                          {transaction.status}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Mobile Cards */}
            <div className="md:hidden divide-y divide-gray-200">
              {transactions.data.map((transaction) => (
                <div key={transaction.id} className="p-4">
                  <div className="flex justify-between items-start mb-2">
                    <div className="flex flex-col">
                      <div className="flex items-center gap-2 mb-1">
                        <span className={`px-2 py-1 text-xs font-medium rounded-full ${typeClasses[transaction.type] || 'bg-gray-100 text-gray-800'}`}>
                          {transaction.type.replace('_', ' ')}
                        </span>
                        <span className={`px-2 py-1 text-xs font-medium rounded-full ${statusClasses[transaction.status] || 'bg-gray-100 text-gray-800'}`}>
                          {transaction.status}
                        </span>
                      </div>
                      <p className="text-sm text-gray-600">
                        {new Date(transaction.created_at).toLocaleDateString()} at {new Date(transaction.created_at).toLocaleTimeString()}
                      </p>
                    </div>
                    <p className="text-lg font-semibold text-gray-900">₵{parseFloat(transaction.amount).toFixed(2)}</p>
                  </div>
                  <p className="text-sm text-gray-900 mb-2">{transaction.description}</p>
                  {(transaction.balance_before || transaction.balance_after) && (
                    <div className="flex justify-between text-xs text-gray-600">
                      <span>Before: {transaction.balance_before ? `₵${parseFloat(transaction.balance_before).toFixed(2)}` : 'N/A'}</span>
                      <span>After: {transaction.balance_after ? `₵${parseFloat(transaction.balance_after).toFixed(2)}` : 'N/A'}</span>
                    </div>
                  )}
                </div>
              ))}
            </div>
          </>
        ) : (
          <div className="px-6 py-8 text-center text-gray-500">
            <FileText className="w-12 h-12 text-gray-300 mx-auto mb-4" />
            <p className="text-lg font-medium">No transactions found</p>
            <p className="text-sm">No transactions match the selected filters.</p>
          </div>
        )}
      </div>

      <Pagination data={transactions} />
    </AdminLayout>
  );
}
