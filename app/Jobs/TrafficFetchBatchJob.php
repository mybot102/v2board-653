<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Batch traffic update job
 * 
 * This job processes traffic updates for multiple users in batches to reduce
 * database connection overhead. Instead of opening one connection per user,
 * it processes users in chunks and explicitly closes connections after each chunk.
 * 
 * This significantly reduces the number of concurrent database connections when
 * handling traffic data from servers with many active users.
 */
class TrafficFetchBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    protected $trafficData;
    protected $server;
    protected $protocol;

    public $tries = 3;
    public $timeout = 60;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $trafficData, array $server, $protocol)
    {
        $this->onQueue('traffic_fetch');
        $this->trafficData = $trafficData;
        $this->server = $server;
        $this->protocol = $protocol;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (empty($this->trafficData)) {
            return;
        }

        $currentTime = time();
        $rate = $this->server['rate'];
        
        // Batch update users in chunks to avoid long-running transactions
        $userIds = array_keys($this->trafficData);
        $chunks = array_chunk($userIds, 100);
        
        foreach ($chunks as $chunk) {
            try {
                DB::transaction(function () use ($chunk, $currentTime, $rate) {
                    foreach ($chunk as $userId) {
                        list($u, $d) = $this->trafficData[$userId];
                        
                        // Calculate the increments safely
                        $uIncrement = (int)($u * $rate);
                        $dIncrement = (int)($d * $rate);
                        
                        // Use parameterized query to avoid SQL injection
                        DB::table('v2_user')
                            ->where('id', $userId)
                            ->update([
                                't' => $currentTime,
                                'u' => DB::raw("u + " . $uIncrement),
                                'd' => DB::raw("d + " . $dIncrement),
                                'updated_at' => $currentTime,
                            ]);
                    }
                }, 3); // 3 attempts for deadlock retries
                
                // Explicitly disconnect after each chunk to free the connection
                DB::disconnect();
            } catch (\Exception $e) {
                Log::error("Batch traffic update failed for chunk", [
                    'error' => $e->getMessage(),
                    'user_ids' => $chunk
                ]);
            }
        }
    }
}
