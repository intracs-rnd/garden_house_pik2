<template>
  <div class="image-test-page">
    <!-- Header -->
    <div class="header">
      <h2>🖼️ Image Upload API Test</h2>
      <p class="subtitle">Test integrasi API upload gambar (192.168.214.7:4000)</p>
    </div>

    <!-- Status Indicator -->
    <div class="status-card">
      <div class="status-item">
        <span class="label">Status API:</span>
        <span :class="['badge', connectionStatus.class]">{{ connectionStatus.text }}</span>
      </div>
      <div class="status-item">
        <span class="label">Terakhir test:</span>
        <span class="value">{{ lastTestTime || '-' }}</span>
      </div>
    </div>

    <!-- Test Form -->
    <div class="test-card">
      <h3>🧪 Test Fetch Image</h3>
      
      <div class="form-group">
        <label for="imagePath">Path Gambar:</label>
        <input
          id="imagePath"
          v-model="imagePath"
          type="text"
          class="input"
          placeholder="/home/transjakarta/Pictures/view/2026/07/16/20260716_161541_MR-IN-anpr.jpg"
        />
        <small class="hint">Masukkan path lengkap gambar di server</small>
      </div>

      <div class="form-group">
        <button @click="testFetchImage" :disabled="loading || !imagePath" class="btn btn-primary">
          <span v-if="loading">⏳ Loading...</span>
          <span v-else>🚀 Test Fetch Image</span>
        </button>
        <button @click="clearResult" class="btn btn-secondary" :disabled="!result && !error">
          🗑️ Clear
        </button>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="loading-card">
      <div class="spinner"></div>
      <p>Menghubungi Image API...</p>
    </div>

    <!-- Error State -->
    <div v-if="error" class="error-card">
      <h4>❌ Error</h4>
      <p>{{ error }}</p>
      <details v-if="errorDetails">
        <summary>Detail Error</summary>
        <pre>{{ errorDetails }}</pre>
      </details>
    </div>

    <!-- Success State -->
    <div v-if="result" class="success-card">
      <h4>✅ Success</h4>
      <div class="result-info">
        <p><strong>Message:</strong> {{ result.message }}</p>
        <p><strong>Success:</strong> {{ result.success ? 'Ya' : 'Tidak' }}</p>
      </div>

      <!-- Image Preview (if URL available) -->
      <div v-if="result.data?.url" class="image-preview">
        <h5>Preview:</h5>
        <img :src="result.data.url" alt="Fetched Image" class="preview-img" />
      </div>

      <!-- Raw Data -->
      <details class="raw-data">
        <summary>📄 Raw Response Data</summary>
        <pre>{{ JSON.stringify(result, null, 2) }}</pre>
      </details>
    </div>

    <!-- Quick Examples -->
    <div class="examples-card">
      <h3>📚 Contoh Path</h3>
      <div class="example-list">
        <div 
          v-for="(example, idx) in examplePaths" 
          :key="idx"
          class="example-item"
          @click="imagePath = example.path"
        >
          <span class="example-label">{{ example.label }}</span>
          <code class="example-path">{{ example.path }}</code>
        </div>
      </div>
    </div>

    <!-- Integration Info -->
    <div class="info-card">
      <h3>ℹ️ Informasi Integrasi</h3>
      <ul>
        <li>✅ Backend Controller: <code>ImageController.php</code></li>
        <li>✅ API Endpoint: <code>POST /api/images/fetch</code></li>
        <li>✅ Vue Composable: <code>useImageFetch()</code></li>
        <li>✅ Image API: <code>http://192.168.214.7:4000/api/uploads</code></li>
      </ul>
      <p class="hint">Lihat dokumentasi lengkap di <code>IMAGE_UPLOAD_INTEGRATION.md</code></p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useImageFetch } from '@/composables/useImageFetch';

const { loading, error: apiError, imageData, getImage } = useImageFetch();

// Form data
const imagePath = ref('/home/transjakarta/Pictures/view/2026/07/16/20260716_161541_MR-IN-anpr.jpg');
const result = ref(null);
const error = ref(null);
const errorDetails = ref(null);
const lastTestTime = ref(null);

// Check on mount
onMounted(() => {
  console.log('✅ ImageTest component mounted');
  console.log('API Base URL:', import.meta.env.VITE_API_BASE_URL);
  console.log('useImageFetch available:', { loading: loading.value, getImage: typeof getImage });
});

// Connection status
const connectionStatus = computed(() => {
  if (loading.value) return { text: 'Testing...', class: 'warning' };
  if (result.value) return { text: 'Connected ✓', class: 'success' };
  if (error.value) return { text: 'Error ✗', class: 'error' };
  return { text: 'Not Tested', class: 'neutral' };
});

// Example paths
const examplePaths = [
  {
    label: 'Default (ANPR)',
    path: '/home/transjakarta/Pictures/view/2026/07/16/20260716_161541_MR-IN-anpr.jpg'
  },
  {
    label: 'Custom Date',
    path: '/home/transjakarta/Pictures/view/2026/07/17/image.jpg'
  },
  {
    label: 'Test Path',
    path: '/home/transjakarta/Pictures/view/test.jpg'
  }
];

// Test fetch image
const testFetchImage = async () => {
  console.log('🧪 Starting image test...');
  console.log('Path:', imagePath.value);
  
  if (!imagePath.value.trim()) {
    error.value = 'Path gambar tidak boleh kosong';
    console.error('❌ Empty path');
    return;
  }

  // Clear previous results
  result.value = null;
  error.value = null;
  errorDetails.value = null;

  try {
    console.log('📡 Calling getImage...');
    const response = await getImage(imagePath.value);
    console.log('✅ Success:', response);
    result.value = response;
    lastTestTime.value = new Date().toLocaleString('id-ID');
  } catch (err) {
    console.error('❌ Error caught:', err);
    console.error('API Error:', apiError.value);
    console.error('Error response:', err.response);
    
    error.value = apiError.value || err.response?.data?.message || err.message || 'Gagal mengambil gambar';
    errorDetails.value = JSON.stringify({
      message: err.message,
      response: err.response?.data,
      status: err.response?.status,
      statusText: err.response?.statusText
    }, null, 2);
  }
};

// Clear results
const clearResult = () => {
  result.value = null;
  error.value = null;
  errorDetails.value = null;
};
</script>

<style scoped>
.image-test-page {
  max-width: 900px;
  margin: 0 auto;
  padding: 20px;
}

.header {
  margin-bottom: 30px;
}

.header h2 {
  margin: 0 0 10px 0;
  color: #2c3e50;
  font-size: 28px;
}

.subtitle {
  color: #7f8c8d;
  margin: 0;
}

.status-card,
.test-card,
.loading-card,
.error-card,
.success-card,
.examples-card,
.info-card {
  background: white;
  border-radius: 8px;
  padding: 20px;
  margin-bottom: 20px;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.status-card {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #f8f9fa;
}

.status-item {
  display: flex;
  align-items: center;
  gap: 10px;
}

.status-item .label {
  font-weight: 600;
  color: #495057;
}

.badge {
  padding: 4px 12px;
  border-radius: 12px;
  font-size: 14px;
  font-weight: 600;
}

.badge.success {
  background: #d4edda;
  color: #155724;
}

.badge.error {
  background: #f8d7da;
  color: #721c24;
}

.badge.warning {
  background: #fff3cd;
  color: #856404;
}

.badge.neutral {
  background: #e9ecef;
  color: #6c757d;
}

.test-card h3,
.examples-card h3,
.info-card h3 {
  margin-top: 0;
  color: #2c3e50;
}

.form-group {
  margin-bottom: 15px;
}

.form-group label {
  display: block;
  margin-bottom: 8px;
  font-weight: 600;
  color: #495057;
}

.input {
  width: 100%;
  padding: 10px;
  border: 2px solid #dee2e6;
  border-radius: 6px;
  font-size: 14px;
  transition: border-color 0.3s;
}

.input:focus {
  outline: none;
  border-color: #4CAF50;
}

.hint {
  display: block;
  margin-top: 5px;
  color: #6c757d;
  font-size: 13px;
}

.btn {
  padding: 10px 20px;
  border: none;
  border-radius: 6px;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s;
  margin-right: 10px;
}

.btn-primary {
  background: #4CAF50;
  color: white;
}

.btn-primary:hover:not(:disabled) {
  background: #45a049;
  transform: translateY(-2px);
  box-shadow: 0 4px 8px rgba(76, 175, 80, 0.3);
}

.btn-secondary {
  background: #6c757d;
  color: white;
}

.btn-secondary:hover:not(:disabled) {
  background: #5a6268;
}

.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
  transform: none;
}

.loading-card {
  text-align: center;
  padding: 40px;
  background: #e3f2fd;
}

.spinner {
  width: 50px;
  height: 50px;
  margin: 0 auto 20px;
  border: 4px solid #f3f3f3;
  border-top: 4px solid #2196F3;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.error-card {
  background: #fff5f5;
  border-left: 4px solid #e53e3e;
}

.error-card h4 {
  margin-top: 0;
  color: #e53e3e;
}

.success-card {
  background: #f0fdf4;
  border-left: 4px solid #22c55e;
}

.success-card h4 {
  margin-top: 0;
  color: #22c55e;
}

.result-info p {
  margin: 8px 0;
}

.image-preview {
  margin-top: 20px;
}

.preview-img {
  max-width: 100%;
  height: auto;
  border-radius: 8px;
  box-shadow: 0 4px 8px rgba(0,0,0,0.2);
  margin-top: 10px;
}

.raw-data {
  margin-top: 15px;
}

.raw-data summary {
  cursor: pointer;
  font-weight: 600;
  color: #495057;
  padding: 8px;
  background: #f8f9fa;
  border-radius: 4px;
}

.raw-data pre {
  background: #263238;
  color: #aed581;
  padding: 15px;
  border-radius: 6px;
  overflow-x: auto;
  font-size: 13px;
  margin-top: 10px;
}

.example-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.example-item {
  padding: 12px;
  background: #f8f9fa;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.3s;
  border: 2px solid transparent;
}

.example-item:hover {
  background: #e9ecef;
  border-color: #4CAF50;
  transform: translateX(5px);
}

.example-label {
  display: block;
  font-weight: 600;
  color: #495057;
  margin-bottom: 5px;
}

.example-path {
  background: #263238;
  color: #aed581;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 13px;
}

.info-card {
  background: #f0f9ff;
}

.info-card ul {
  margin: 10px 0;
  padding-left: 20px;
}

.info-card li {
  margin: 8px 0;
}

.info-card code {
  background: #263238;
  color: #aed581;
  padding: 2px 6px;
  border-radius: 3px;
  font-size: 13px;
}

details summary {
  user-select: none;
}

details[open] summary {
  margin-bottom: 10px;
}
</style>
