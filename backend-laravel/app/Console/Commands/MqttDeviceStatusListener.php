<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * MQTT Listener for Device Status.
 * 
 * Subscribes to the `get/+/status` topic and publishes JSON formatted
 * status back to another topic (e.g., `dashboard/device/status`).
 * 
 * Run: php artisan mqtt:device-status
 */
class MqttDeviceStatusListener extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mqtt:device-status
                            {--host=192.168.214.163 : MQTT broker host}
                            {--port=1883 : MQTT broker port}
                            {--username=dev : MQTT username}
                            {--password=dev : MQTT password}
                            {--topic=get/+/status : MQTT topic to subscribe}
                            {--publish-topic=dashboard/device/status : Topic to publish JSON result}
                            {--qos=1 : MQTT QoS level}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Listen to MQTT topic for device status and broadcast as JSON';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (!class_exists('PhpMqtt\Client\MqttClient')) {
            $this->error('MQTT Client not installed! Please run: composer require php-mqtt/client');
            return 1;
        }

        $host = $this->option('host');
        $port = (int) $this->option('port');
        $username = $this->option('username');
        $password = $this->option('password');
        $topic = $this->option('topic');
        $publishTopic = $this->option('publish-topic');
        $qos = (int) $this->option('qos');

        $this->info("🚀 Starting MQTT Device Status Listener...");
        $this->line("📡 Broker: {$host}:{$port}");
        $this->line("📋 Listening to: {$topic}");
        $this->line("📤 Will publish to: {$publishTopic}");
        $this->line('');
        $this->warn('Press Ctrl+C to stop');
        $this->line('');

        try {
            $mqtt = new \PhpMqtt\Client\MqttClient($host, $port, 'laravel-device-status-' . time());

            $connectionSettings = (new \PhpMqtt\Client\ConnectionSettings())
                ->setUsername($username)
                ->setPassword($password)
                ->setKeepAliveInterval(60)
                ->setConnectTimeout(10)
                ->setUseTls(false)
                ->setTlsSelfSignedAllowed(false);

            $mqtt->connect($connectionSettings, true);
            $this->info('✅ Connected to MQTT broker');

            $mqtt->subscribe($topic, function ($topic, $message) use ($mqtt, $publishTopic) {
                $this->handleMessage($mqtt, $topic, $message, $publishTopic);
            }, $qos);

            $this->info("✅ Subscribed to wildcard: {$topic}");
            $this->line('');
            $this->line('🎧 Waiting for device status messages...');
            $this->line('');

            $mqtt->loop(true);
            $mqtt->disconnect();

        } catch (\Exception $e) {
            $this->error('❌ MQTT Error: ' . $e->getMessage());
            Log::error('MQTT Device Status Listener Error', [
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
     * @param \PhpMqtt\Client\MqttClient $mqtt
     * @param string $topic
     * @param string $message
     * @param string $publishTopic
     * @return void
     */
    protected function handleMessage($mqtt, string $topic, string $message, string $publishTopic): void
    {
        $this->line('📨 [' . now()->format('Y-m-d H:i:s') . '] Received on: ' . $topic . ' | Payload: ' . $message);

        try {
            // Extract device name from topic (e.g. get/device-01/status)
            $parts = explode('/', $topic);
            
            // Validate the wildcard topic format: get/{nama_device}/status
            if (count($parts) >= 3 && $parts[0] === 'get' && $parts[2] === 'status') {
                $deviceName = $parts[1];
                $status = trim($message);
                
                // Format the JSON data as requested
                $data = [
                    'nama_device' => $deviceName,
                    'status' => strtolower($status)
                ];
                
                $jsonPayload = json_encode($data);
                $this->info('📦 Formatted JSON: ' . $jsonPayload);
                
                // "Kirim" the JSON format by publishing to another topic (can also broadcast to WebSocket/save to DB)
                $mqtt->publish($publishTopic, $jsonPayload, 1);
                $this->info("📤 Sent JSON to topic: {$publishTopic}");
                
                // You can also add DB logic or Event broadcasting here if needed
            } else {
                $this->warn("⚠️  Ignored topic format: {$topic}");
            }

        } catch (\Exception $e) {
            $this->error('❌ Error processing message: ' . $e->getMessage());
        }
    }
}
