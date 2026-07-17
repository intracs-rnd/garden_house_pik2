<template>
  <div class="test-page">
    <h2>🖼️ Image API Test (Simple Debug Version)</h2>
    
    <!-- Debug Info -->
    <div class="debug-section">
      <h3>🔍 Debug Info</h3>
      <p>✅ Component loaded</p>
      <p>API URL: {{ apiBaseUrl }}</p>
      <p>Loading state: {{ loading }}</p>
      <p>Has getImage function: {{ hasGetImage }}</p>
    </div>

    <!-- Test Section -->
    <div class="test-section">
      <h3>Test Fetch Image</h3>
      
      <label>Image Path:</label>
      <input v-model="imagePath" type="text" class="input" />
      
      <button @click="handleTest" :disabled="loading" class="btn">
        {{ loading ? 'Loading...' : 'Test Now' }}
      </button>
    </div>

    <!-- Console Log -->
    <div class="console-section">
      <h3>📋 Console Log</h3>
      <div class="console">
        <div v-for="(log, idx) in logs" :key="idx" :class="['log-item', log.type]">
          {{ log.time }} - {{ log.message }}
        </div>
      </div>
    </div>

    <!-- Result -->
    <div v-if="result" class="result-section success">
      <h3>✅ Success!</h3>
      <pre>{{ JSON.stringify(result, null, 2) }}</pre>
    </div>

    <!-- Error -->
    <div v-if="error" class="result-section error">
      <h3>❌ Error</h3>
      <p>{{ error }}</p>
      <pre v-if="errorDetail">{{ errorDetail }}</pre>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import apiClient from '@/api/axios';

const apiBaseUrl = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api';
const imagePath = ref('/home/transjakarta/Pictures/view/2026/07/16/20260716_161541_MR-IN-anpr.jpg');
const loading = ref(false);
const result = ref(null);
const error = ref(null);
const errorDetail = ref(null);
const logs = ref([]);
const hasGetImage = ref(true);

function addLog(message, type = 'info') {
  const time = new Date().toLocaleTimeString('id-ID');
  logs.value.push({ time, message, type });
  console.log(`[${time}] ${message}`);
}

onMounted(() => {
  addLog('✅ Component mounted', 'success');
  addLog(`API Base: ${apiBaseUrl}`, 'info');
});

async function handleTest() {
  addLog('🚀 Starting test...', 'info');
  
  loading.value = true;
  result.value = null;
  error.value = null;
  errorDetail.value = null;

  try {
    addLog(`📡 Calling POST ${apiBaseUrl}/images/fetch`, 'info');
    addLog(`Path: ${imagePath.value}`, 'info');

    const response = await apiClient.post('/images/fetch', {
      path: imagePath.value
    });

    addLog('✅ Response received', 'success');
    result.value = response.data;
    
  } catch (err) {
    addLog('❌ Error occurred', 'error');
    error.value = err.message;
    errorDetail.value = JSON.stringify({
      message: err.message,
      status: err.response?.status,
      statusText: err.response?.statusText,
      data: err.response?.data
    }, null, 2);
    
    console.error('Full error:', err);
    
  } finally {
    loading.value = false;
    addLog('✅ Test completed', 'info');
  }
}
</script>

<style scoped>
.test-page {
  max-width: 800px;
  margin: 20px auto;
  padding: 20px;
}

h2 {
  color: #2c3e50;
  border-bottom: 2px solid #4CAF50;
  padding-bottom: 10px;
}

h3 {
  color: #34495e;
  margin-top: 20px;
}

.debug-section,
.test-section,
.console-section,
.result-section {
  background: white;
  padding: 20px;
  margin: 20px 0;
  border-radius: 8px;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.debug-section p {
  margin: 5px 0;
  font-family: monospace;
}

.input {
  width: 100%;
  padding: 10px;
  border: 2px solid #ddd;
  border-radius: 4px;
  margin: 10px 0;
  font-size: 14px;
}

.btn {
  padding: 12px 24px;
  background: #4CAF50;
  color: white;
  border: none;
  border-radius: 6px;
  font-size: 16px;
  cursor: pointer;
  font-weight: bold;
}

.btn:hover:not(:disabled) {
  background: #45a049;
}

.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.console {
  background: #263238;
  color: #aed581;
  padding: 15px;
  border-radius: 4px;
  max-height: 300px;
  overflow-y: auto;
  font-family: 'Courier New', monospace;
  font-size: 13px;
}

.log-item {
  margin: 5px 0;
  padding: 5px;
}

.log-item.success {
  color: #4CAF50;
}

.log-item.error {
  color: #f44336;
}

.log-item.info {
  color: #aed581;
}

.result-section.success {
  background: #f0fdf4;
  border-left: 4px solid #4CAF50;
}

.result-section.error {
  background: #fff5f5;
  border-left: 4px solid #f44336;
}

pre {
  background: #263238;
  color: #aed581;
  padding: 15px;
  border-radius: 4px;
  overflow-x: auto;
  font-size: 12px;
}
</style>
