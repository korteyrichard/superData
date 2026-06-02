<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FixCodeCraftConfig extends Command
{
    protected $signature = 'fix:codecraft-config';
    protected $description = 'Fix CodeCraft configuration by clearing and recaching config';

    public function handle()
    {
        $this->info('Fixing CodeCraft configuration...');
        
        // Clear config cache
        $this->call('config:clear');
        $this->info('✅ Configuration cache cleared');
        
        // Clear application cache
        $this->call('cache:clear');
        $this->info('✅ Application cache cleared');
        
        // Check configuration
        $this->call('check:codecraft-config');
        
        $this->info('');
        $this->info('Configuration fix completed!');
        $this->info('You can now try placing an MTN order again.');
        
        return 0;
    }
}