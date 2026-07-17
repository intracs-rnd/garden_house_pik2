/**
 * Vue Composable untuk Image Upload API
 * Hook untuk fetch gambar dengan loading & error state
 */

import { ref } from 'vue';
import { fetchImage, fetchMultipleImages, buildImagePath } from '@/api/image';

export function useImageFetch() {
  const loading = ref(false);
  const error = ref(null);
  const imageData = ref(null);

  /**
   * Fetch single image
   */
  const getImage = async (imagePath) => {
    loading.value = true;
    error.value = null;
    imageData.value = null;

    try {
      const response = await fetchImage(imagePath);
      imageData.value = response.data;
      return response.data;
    } catch (err) {
      error.value = err.response?.data?.message || 'Gagal mengambil gambar';
      throw err;
    } finally {
      loading.value = false;
    }
  };

  /**
   * Fetch multiple images
   */
  const getMultipleImages = async (imagePaths) => {
    loading.value = true;
    error.value = null;
    imageData.value = null;

    try {
      const response = await fetchMultipleImages(imagePaths);
      imageData.value = response;
      return response;
    } catch (err) {
      error.value = err.response?.data?.message || 'Gagal mengambil gambar';
      throw err;
    } finally {
      loading.value = false;
    }
  };

  return {
    loading,
    error,
    imageData,
    getImage,
    getMultipleImages,
    buildImagePath,
  };
}
