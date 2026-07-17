<template>
  <div class="image-viewer">
    <!-- Single Image Fetch -->
    <div class="section">
      <h3>Fetch Single Image</h3>
      <div class="form-group">
        <label>Image Path:</label>
        <input 
          v-model="singleImagePath" 
          type="text" 
          placeholder="/home/transjakarta/Pictures/view/2026/07/16/20260716_161541_MR-IN-anpr.jpg"
          class="input"
        />
        <button @click="handleFetchImage" :disabled="loading" class="btn">
          {{ loading ? 'Loading...' : 'Fetch Image' }}
        </button>
      </div>

      <!-- Display single image -->
      <div v-if="error" class="error">{{ error }}</div>
      <div v-if="imageData" class="image-result">
        <h4>Result:</h4>
        <pre>{{ JSON.stringify(imageData, null, 2) }}</pre>
        <!-- Jika API return URL -->
        <img v-if="imageData.url" :src="imageData.url" alt="Fetched Image" class="preview-image" />
      </div>
    </div>

    <!-- Multiple Images Fetch -->
    <div class="section">
      <h3>Fetch Multiple Images</h3>
      <div class="form-group">
        <label>Image Paths (one per line):</label>
        <textarea 
          v-model="multipleImagePaths" 
          rows="5"
          placeholder="/home/transjakarta/Pictures/view/2026/07/16/image1.jpg
/home/transjakarta/Pictures/view/2026/07/16/image2.jpg"
          class="textarea"
        ></textarea>
        <button @click="handleFetchMultipleImages" :disabled="loading" class="btn">
          {{ loading ? 'Loading...' : 'Fetch Multiple Images' }}
        </button>
      </div>

      <!-- Display multiple images -->
      <div v-if="multipleResults" class="images-result">
        <h4>Results ({{ multipleResults.succeeded }}/{{ multipleResults.total }} succeeded):</h4>
        <div v-for="(result, idx) in multipleResults.results" :key="idx" class="result-item">
          <p><strong>Path:</strong> {{ result.path }}</p>
          <img v-if="result.data?.url" :src="result.data.url" alt="Image" class="preview-image-small" />
        </div>
      </div>
    </div>

    <!-- Helper untuk build path -->
    <div class="section">
      <h3>Path Builder Helper</h3>
      <div class="form-group">
        <label>Date Folder:</label>
        <input v-model="dateFolder" type="text" placeholder="2026/07/16" class="input" />
        <label>Filename:</label>
        <input v-model="filename" type="text" placeholder="20260716_161541_MR-IN-anpr.jpg" class="input" />
        <p><strong>Built Path:</strong> {{ builtPath }}</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useImageFetch } from '@/composables/useImageFetch';

const { loading, error, imageData, getImage, getMultipleImages, buildImagePath } = useImageFetch();

// Single image
const singleImagePath = ref('/home/transjakarta/Pictures/view/2026/07/16/20260716_161541_MR-IN-anpr.jpg');

const handleFetchImage = async () => {
  try {
    await getImage(singleImagePath.value);
  } catch (err) {
    console.error('Failed to fetch image:', err);
  }
};

// Multiple images
const multipleImagePaths = ref('');
const multipleResults = ref(null);

const handleFetchMultipleImages = async () => {
  const paths = multipleImagePaths.value
    .split('\n')
    .map(p => p.trim())
    .filter(p => p.length > 0);

  if (paths.length === 0) {
    alert('Please enter at least one image path');
    return;
  }

  try {
    const result = await getMultipleImages(paths);
    multipleResults.value = result;
  } catch (err) {
    console.error('Failed to fetch multiple images:', err);
  }
};

// Path builder
const dateFolder = ref('2026/07/16');
const filename = ref('20260716_161541_MR-IN-anpr.jpg');
const builtPath = computed(() => buildImagePath(dateFolder.value, filename.value));
</script>

<style scoped>
.image-viewer {
  padding: 20px;
  max-width: 800px;
  margin: 0 auto;
}

.section {
  margin-bottom: 40px;
  padding: 20px;
  border: 1px solid #e0e0e0;
  border-radius: 8px;
}

.form-group {
  margin-bottom: 15px;
}

.form-group label {
  display: block;
  margin-bottom: 5px;
  font-weight: bold;
}

.input,
.textarea {
  width: 100%;
  padding: 8px;
  border: 1px solid #ccc;
  border-radius: 4px;
  font-size: 14px;
  margin-bottom: 10px;
}

.btn {
  padding: 10px 20px;
  background-color: #4CAF50;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
}

.btn:hover:not(:disabled) {
  background-color: #45a049;
}

.btn:disabled {
  background-color: #ccc;
  cursor: not-allowed;
}

.error {
  padding: 10px;
  background-color: #ffebee;
  color: #c62828;
  border-radius: 4px;
  margin: 10px 0;
}

.image-result,
.images-result {
  margin-top: 20px;
  padding: 15px;
  background-color: #f5f5f5;
  border-radius: 4px;
}

.preview-image {
  max-width: 100%;
  height: auto;
  border-radius: 4px;
  margin-top: 10px;
}

.preview-image-small {
  max-width: 200px;
  height: auto;
  border-radius: 4px;
  margin-top: 10px;
}

.result-item {
  padding: 10px;
  background-color: white;
  border-radius: 4px;
  margin-bottom: 10px;
}

pre {
  background-color: #263238;
  color: #aed581;
  padding: 15px;
  border-radius: 4px;
  overflow-x: auto;
  font-size: 12px;
}
</style>
