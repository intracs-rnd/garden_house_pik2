<?php

namespace App\Console\Commands;

use App\Models\Kartu;
use App\Models\KartuAccessLog;
use App\Repositories\KartuRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * MQTT Listener for RFID gate status.
 * 
 * Subscribes to the `gate/in/rfid_status` topic and saves incoming
 * tap events to the kartu_access_logs table.
 * 
 * Run: php artisan mqtt:rfid-listener
 * 
 * Requirements:
 * - Install php-mqtt package: composer require php-mqtt/client
 * - Or use mosquitto-php extension
 */
class MqttRfidListener extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mqtt:rfid-listener
                            {--host=192.168.214.7 : MQTT broker host}
                            {--port=1883 : MQTT broker port}
                            {--username=dev : MQTT username}
                            {--password=dev : MQTT password}
                            {--topic=gate/in/rfid_status : MQTT topic to subscribe}
                            {--qos=1 : MQTT QoS level}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Listen to MQTT topic for RFID gate status and save to database';

    protected KartuRepository $kartuRepository;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(KartuRepository $kartuRepository)
    {
        parent::__construct();
        $this->kartuRepository = $kartuRepository;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Check if PhpMqtt\Client\MqttClient is available
        if (!class_exists('PhpMqtt\Client\MqttClient')) {
            $this->error('MQTT Client not installed!');
            $this->line('');
            $this->info('Install it with:');
            $this->line('  composer require php-mqtt/client');
            $this->line('');
            return 1;
        }

        $host = $this->option('host');
        $port = (int) $this->option('port');
        $username = $this->option('username');
        $password = $this->option('password');
        $topic = $this->option('topic');
        $qos = (int) $this->option('qos');

        $this->info("🚀 Starting MQTT RFID Listener...");
        $this->line("📡 Broker: {$host}:{$port}");
        $this->line("📋 Topic: {$topic} (QoS: {$qos})");
        $this->line("👤 User: {$username}");
        $this->line('');
        $this->warn('Press Ctrl+C to stop');
        $this->line('');

        try {
            $mqtt = new \PhpMqtt\Client\MqttClient($host, $port, 'laravel-rfid-listener-' . time());

            $connectionSettings = (new \PhpMqtt\Client\ConnectionSettings())
                ->setUsername($username)
                ->setPassword($password)
                ->setKeepAliveInterval(60)
                ->setConnectTimeout(10)
                ->setUseTls(false)
                ->setTlsSelfSignedAllowed(false);

            $mqtt->connect($connectionSettings, true);

            $this->info('✅ Connected to MQTT broker');

            $mqtt->subscribe($topic, function ($topic, $message) {
                $this->handleMessage($topic, $message);
            }, $qos);

            $this->info("✅ Subscribed to: {$topic}");
            $this->line('');
            $this->line('🎧 Listening for messages...');
            $this->line('');

            // Run the event loop
            $mqtt->loop(true);

            $mqtt->disconnect();

        } catch (\Exception $e) {
            $this->error('❌ MQTT Error: ' . $e->getMessage());
            Log::error('MQTT RFID Listener Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 1;
        }

        return 0;
    }

    /**
     * Handle incoming MQTT message.
     *
     * @param string $topic
     * @param string $message
     * @return void
     */
    protected function handleMessage(string $topic, string $message): void
    {
        $this->line('📨 [' . now()->format('Y-m-d H:i:s') . '] Message received on: ' . $topic);
        $this->line('📄 Raw message: ' . $message);

        try {
            // Parse pipe-delimited format: GATE_ID|DEVICE|STATUS|MESSAGE|TIMESTAMP
            $parts = explode('|', $message);
            
            if (count($parts) !== 5) {
                $this->error('❌ Invalid format. Expected 5 fields, got ' . count($parts));
                $this->error('🔍 Format: GATE_ID|DEVICE|STATUS|MESSAGE|TIMESTAMP');
                return;
            }

            $data = [
                'gate_id' => trim($parts[0]),
                'device_type' => trim($parts[1]),
                'status' => trim($parts[2]),
                'message' => trim($parts[3]),
                'timestamp' => trim($parts[4]),
            ];

            $this->line('📦 Parsed data:');
            $this->line('   Gate: ' . $data['gate_id']);
            $this->line('   Device: ' . $data['device_type']);
            $this->line('   Status: ' . $data['status']);
            $this->line('   Message: ' . $data['message']);
            $this->line('   Timestamp: ' . $data['timestamp']);

            $this->info('✅ Message parsed successfully');

            // TODO: Jika perlu save ke database, uncomment ini
            // $this->saveToLogRfidConn($data);

        } catch (\Exception $e) {
            $this->error('❌ Error processing message: ' . $e->getMessage());
            Log::error('MQTT: Error processing message', [
                'error' => $e->getMessage(),
                'message' => $message,
            ]);
        }
    }

    /**
     * Save MQTT data to kartu_access_logs table.
     *
     * @param array $data
     * @return void
     */
    protected function saveToDatabase(array $data): void
    {
        $cardNumber = $data['card_number'] ?? $data['cardNumber'] ?? null;

        if (!$cardNumber) {
            $this->warn('⚠️  No card_number in message, skipping save');
            return;
        }

        // Find kartu by card_number
        $kartu = $this->kartuRepository->findByCardNumber($cardNumber);

        $direction = $data['direction'] ?? 1; // Default: IN
        $accessGranted = $data['access_granted'] ?? $data['accessGranted'] ?? false;
        $reason = $data['reason'] ?? 'mqtt';
        $gate = $data['gate'] ?? 'MQTT';
        $tappedAt = isset($data['timestamp']) ? now()->parse($data['timestamp']) : now();

        $logData = [
            'kartu_id'       => $kartu ? $kartu->id : null,
            'user_id'        => $kartu ? $kartu->user_id : null,
            'card_number'    => $cardNumber,
            'no_plat'        => $data['no_plat'] ?? $data['plate'] ?? null,
            'direction'      => $direction,
            'access_granted' => $accessGranted,
            'reason'         => $reason,
            'gate'           => $gate,
            'tapped_at'      => $tappedAt,
        ];

        $log = KartuAccessLog::create($logData);

        $status = $accessGranted ? '✅ GRANTED' : '❌ DENIED';
        $dirLabel = $direction == 1 ? 'IN' : 'OUT';
        
        $this->info("💾 Saved to DB: #{$log->id} | {$cardNumber} | {$dirLabel} | {$status} | {$gate}");

        Log::info('MQTT: RFID tap saved', [
            'log_id' => $log->id,
            'card_number' => $cardNumber,
            'direction' => $direction,
            'access_granted' => $accessGranted,
        ]);
    }
}
