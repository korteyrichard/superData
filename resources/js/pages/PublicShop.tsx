import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

interface ShopProduct {
    id: number;
    name: string;
    description: string;
    network: string;
    base_price: number;
    agent_price: number;
    product_type: string;
    status: string;
    quantity: number;
}

interface Shop {
    name: string;
    username: string;
    agent_name: string;
    color?: string;
}

interface PublicShopProps {
    shop: Shop;
    products: ShopProduct[];
    auth?: {
        user?: {
            id: number;
            name: string;
            email: string;
        }
    };
}

export default function PublicShop({ shop, products, auth }: PublicShopProps) {
    const [selectedNetwork, setSelectedNetwork] = useState<string>('all');
    const [selectedProduct, setSelectedProduct] = useState<ShopProduct | null>(null);
    const [showPurchaseModal, setShowPurchaseModal] = useState(false);
    
    const { data, setData, post, processing } = useForm({
        product_id: '',
        quantity: 1,
        beneficiary_number: '',
        agent_username: shop.username,
        customer_email: '',
        customer_phone: ''
    });
    
    const getNetworkColor = (network: string) => {
        const colors: { [key: string]: string } = {
            'MTN': 'bg-yellow-500',
            'VODAFONE': 'bg-red-500', 
            'AIRTELTIGO': 'bg-blue-500',
            'TELECEL': 'bg-green-500'
        };
        return colors[network.toUpperCase()] || 'bg-gray-500';
    };
    
    const networks = ['all', ...Array.from(new Set(products.map(p => p.network)))];
    const filteredProducts = selectedNetwork === 'all' 
        ? products 
        : products.filter(p => p.network === selectedNetwork);

    const handlePurchase = (product: ShopProduct) => {
        console.log('Product selected:', product);
        setSelectedProduct(product);
        // Extract number from quantity string like "2GB" -> 2
        const qty = parseInt(product.quantity.toString()) || 1;
        console.log('Setting quantity to:', qty);
        setData({
            product_id: product.id.toString(),
            quantity: qty,
            beneficiary_number: '',
            agent_username: shop.username,
            customer_email: '',
            customer_phone: ''
        });
        setShowPurchaseModal(true);
    };

    const submitPurchase = (e: React.FormEvent) => {
        e.preventDefault();
        if (!auth?.user) {
            setData('customer_phone', data.beneficiary_number);
        }
        post('/shop/purchase', {
            onError: (errors) => {
                console.error('Purchase error:', errors);
            }
        });
    };

    return (
        <>
            <Head title={`${shop.name} - SuperData Agent Shop`} />
            
            <div 
                className="min-h-screen"
                style={{ 
                    background: shop.color 
                        ? `linear-gradient(135deg, ${shop.color}20, ${shop.color}10, #ffffff)` 
                        : 'linear-gradient(135deg, #EFF6FF, #F0FDF4, #ffffff)' 
                }}
            >
                <div 
                    className="shadow-lg border-b border-gray-100"
                    style={{
                        backgroundColor: shop.color || '#3B82F6'
                    }}
                >
                    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                        <div className="text-center">
                            <div 
                                className="inline-flex items-center justify-center w-16 h-16 rounded-full mb-4"
                                style={{ 
                                    background: shop.color ? `linear-gradient(135deg, ${shop.color}, ${shop.color}dd)` : 'linear-gradient(135deg, #3B82F6, #10B981)'
                                }}
                            >
                                <svg className="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
                                </svg>
                            </div>
                            <h1 className="text-4xl md:text-5xl font-bold text-white mb-2">{shop.name}</h1>
                            <div 
                                className="inline-flex items-center px-4 py-2 rounded-full"
                                style={{ 
                                    backgroundColor: 'rgba(255, 255, 255, 0.2)'
                                }}
                            >
                                <span className="w-2 h-2 bg-green-500 rounded-full mr-2 animate-pulse"></span>
                                <span className="text-sm font-semibold text-white">Official Agent Shop</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                    <div className="mb-8">
                        <div className="flex flex-wrap justify-center gap-4">
                            {networks.map((network) => (
                                <button
                                    key={network}
                                    onClick={() => setSelectedNetwork(network)}
                                    className={`px-8 py-4 rounded-2xl text-sm font-bold transition-all duration-300 transform hover:scale-105 ${
                                        selectedNetwork === network
                                            ? network === 'all' 
                                                ? 'bg-gradient-to-r from-gray-700 to-gray-800 text-white shadow-2xl'
                                                : `${getNetworkColor(network)} text-white shadow-2xl`
                                            : 'bg-white text-gray-700 hover:bg-gray-50 border-2 border-gray-200 hover:border-gray-300 shadow-lg'
                                    }`}
                                >
                                    {network === 'all' ? '🌐 All Networks' : `📱 ${network}`}
                                </button>
                            ))}
                        </div>
                    </div>

                    {filteredProducts.length > 0 ? (
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                            {filteredProducts.map((product) => (
                                <Card key={product.id} className="group hover:shadow-xl transition-all duration-300 border-0 shadow-md bg-white rounded-2xl overflow-hidden transform hover:-translate-y-1">
                                    <CardHeader className="pb-2 bg-gradient-to-br from-gray-50 to-white">
                                        <div className="flex justify-between items-start mb-2">
                                            <CardTitle className="text-lg font-bold text-gray-900 group-hover:text-blue-600 transition-colors">{product.name}</CardTitle>
                                            <Badge 
                                                variant={product.status === 'IN STOCK' ? 'default' : 'secondary'} 
                                                className={`text-xs px-2 py-1 font-semibold ${
                                                    product.status === 'IN STOCK' 
                                                        ? 'bg-green-100 text-green-800 border border-green-200' 
                                                        : 'bg-red-100 text-red-800 border border-red-200'
                                                }`}
                                            >
                                                {product.status === 'IN STOCK' ? '✓ Available' : '⚠ Out of Stock'}
                                            </Badge>
                                        </div>
                                        <div className="flex items-center space-x-2">
                                            <div className={`w-3 h-3 rounded-full ${getNetworkColor(product.network)} shadow-sm`}></div>
                                            <p className="text-sm font-bold text-gray-700 uppercase tracking-wide">{product.network}</p>
                                        </div>
                                    </CardHeader>
                                    <CardContent className="pt-1">
                                        <div className="space-y-2">
                                            <p className="text-sm text-gray-600 leading-relaxed">{product.description}</p>
                                            
                                            <div className="text-center py-3 bg-gradient-to-r from-blue-50 to-green-50 rounded-xl">
                                                <p className="text-xs text-gray-500 mb-1">Price</p>
                                                <p className="text-2xl font-black text-transparent bg-clip-text bg-gradient-to-r from-blue-600 to-green-600">
                                                    ₵{Number(product.agent_price).toFixed(2)}
                                                </p>
                                            </div>

                                            <Button 
                                                className="w-full font-bold py-2 text-sm rounded-xl shadow-md transition-all duration-300 transform hover:scale-105 text-white hover:shadow-lg"
                                                style={{
                                                    background: product.status === 'IN STOCK' && shop.color 
                                                        ? `linear-gradient(135deg, ${shop.color}, ${shop.color}dd)` 
                                                        : product.status === 'IN STOCK' 
                                                        ? 'linear-gradient(135deg, #10B981, #059669)' 
                                                        : '#D1D5DB',
                                                    color: product.status !== 'IN STOCK' ? '#6B7280' : 'white'
                                                }}
                                                onClick={() => handlePurchase(product)}
                                                disabled={product.status !== 'IN STOCK'}
                                            >
                                                {product.status === 'IN STOCK' ? '💳 BUY NOW' : '⚠ OUT OF STOCK'}
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    ) : (
                        <div className="text-center py-20">
                            <div className="w-24 h-24 mx-auto mb-6 bg-gradient-to-r from-gray-100 to-gray-200 rounded-full flex items-center justify-center">
                                <svg className="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                </svg>
                            </div>
                            <h3 className="text-2xl font-bold text-gray-900 mb-3">No Products Available</h3>
                            <p className="text-gray-600 text-lg max-w-md mx-auto">
                                {selectedNetwork === 'all' 
                                    ? 'This shop is currently being stocked with amazing products. Please check back soon!' 
                                    : `No ${selectedNetwork} products are currently available. Try selecting a different network.`
                                }
                            </p>
                        </div>
                    )}
                </div>


            </div>

            {showPurchaseModal && selectedProduct && (
                <div className="fixed inset-0 bg-black bg-opacity-60 z-50 overflow-y-auto">
                    <div className="flex min-h-full items-center justify-center p-4">
                        <div className="bg-white rounded-3xl p-8 w-full max-w-lg shadow-2xl transform transition-all my-8">
                        <div className="text-center mb-6">
                            <div 
                                className="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4"
                                style={{
                                    background: shop.color 
                                        ? `linear-gradient(135deg, ${shop.color}, ${shop.color}dd)` 
                                        : 'linear-gradient(135deg, #3B82F6, #10B981)'
                                }}
                            >
                                <svg className="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-2.5 5M7 13l2.5 5m6-5v6a2 2 0 01-2 2H9a2 2 0 01-2-2v-6m6 0V9a2 2 0 00-2-2H9a2 2 0 00-2 2v4.01" />
                                </svg>
                            </div>
                            <h3 className="text-2xl font-bold text-gray-900 mb-2">Complete Your Purchase</h3>
                            <p className="text-gray-600">{selectedProduct.name}</p>
                        </div>
                        <form onSubmit={submitPurchase} method="POST" action="/shop/purchase" className="space-y-6">
                            <input type="hidden" name="_token" value={document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''} />
                            <input type="hidden" name="product_id" value={data.product_id} />
                            <input type="hidden" name="quantity" value={data.quantity} />
                            <input type="hidden" name="agent_username" value={data.agent_username} />
                            {!auth?.user && (
                                <div>
                                    <label className="block text-sm font-bold text-gray-700 mb-2">📧 Email Address</label>
                                    <Input
                                        type="email"
                                        name="customer_email"
                                        value={data.customer_email || ''}
                                        onChange={(e) => setData('customer_email', e.target.value)}
                                        placeholder="Enter your email address"
                                        className="w-full px-4 py-3 rounded-xl border-2 border-gray-200 focus:border-blue-500 transition-colors"
                                        required
                                    />
                                </div>
                            )}
                            <div>
                                <label className="block text-sm font-bold text-gray-700 mb-2">📱 Beneficiary Phone Number</label>
                                <Input
                                    type="text"
                                    name="beneficiary_number"
                                    maxLength={10}
                                    minLength={10}
                                    pattern="[0-9]{10}"
                                    value={data.beneficiary_number}
                                    onChange={(e) => setData('beneficiary_number', e.target.value)}
                                    placeholder="0XXXXXXXXX"
                                    className="w-full px-4 py-3 rounded-xl border-2 border-gray-200 focus:border-blue-500 transition-colors"
                                    required
                                />
                                <p className="text-xs text-gray-500 mt-1">Enter the phone number that will receive the data/airtime</p>
                            </div>
                            <div 
                                className="p-6 rounded-2xl border"
                                style={{
                                    backgroundColor: shop.color ? `${shop.color}15` : '#EFF6FF',
                                    borderColor: shop.color ? `${shop.color}50` : '#BFDBFE'
                                }}
                            >
                                <div className="flex items-center justify-between mb-3">
                                    <span 
                                        className="font-bold text-lg"
                                        style={{ color: shop.color || '#1D4ED8' }}
                                    >
                                        💰 Order Summary
                                    </span>
                                </div>
                                <div className="space-y-2">
                                    <div className="flex justify-between">
                                        <span className="text-gray-600">Product:</span>
                                        <span className="font-semibold">{selectedProduct.name}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-gray-600">Network:</span>
                                        <span className="font-semibold">{selectedProduct.network}</span>
                                    </div>
                                    <div 
                                        className="border-t pt-2 mt-3"
                                        style={{ borderColor: shop.color ? `${shop.color}50` : '#BFDBFE' }}
                                    >
                                        <div 
                                            className="flex justify-between text-2xl font-black"
                                            style={{ color: shop.color || '#1D4ED8' }}
                                        >
                                            <span>Total:</span>
                                            <span>₵{Number(selectedProduct.agent_price).toFixed(2)}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div className="flex flex-col sm:flex-row gap-3">
                                <Button 
                                    type="button" 
                                    variant="outline"
                                    onClick={() => setShowPurchaseModal(false)}
                                    className="flex-1 py-3 rounded-xl border-2 border-gray-300 hover:border-gray-400 font-semibold"
                                >
                                    Cancel
                                </Button>
                                <Button 
                                    type="submit" 
                                    disabled={processing} 
                                    className="flex-1 text-white font-bold py-3 rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 transform hover:scale-105"
                                    style={{
                                        background: shop.color 
                                            ? `linear-gradient(135deg, ${shop.color}, ${shop.color}dd)` 
                                            : 'linear-gradient(135deg, #10B981, #059669)'
                                    }}
                                >
                                    {processing ? '⏳ Processing...' : '🚀 Place Order'}
                                </Button>
                            </div>
                        </form>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}