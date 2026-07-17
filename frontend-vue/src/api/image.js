/**
 * Image Upload API Service
 * Service untuk fetch gambar dari image upload API via Laravel proxy
 */

import apiClient from './axios';

/**
 * Fetch single image dari path
 * @param {string} imagePath - Path lengkap gambar di server
 * @returns {Promise<Object>} Response dengan data gambar
 */
export async function fetchImage(imagePath) {
  try {
    const response = await apiClient.post('/images/fetch', {
      path: imagePath,
    });
    return response.data;
  } catch (error) {
    console.error('Error fetching image:', error);
    throw error;
  }
}

/**
 * Fetch multiple images sekaligus (batch)
 * @param {Array<string>} imagePaths - Array path gambar
 * @returns {Promise<Object>} Response dengan results array
 */
export async function fetchMultipleImages(imagePaths) {
  try {
    const response = await apiClient.post('/images/fetch-multiple', {
      paths: imagePaths,
    });
    return response.data;
  } catch (error) {
    console.error('Error fetching multiple images:', error);
    throw error;
  }
}

/**
 * Helper: Build image path dari date dan filename
 * Contoh: buildImagePath('2026/07/16', '20260716_161541_MR-IN-anpr.jpg')
 * Output: '/home/transjakarta/Pictures/view/2026/07/16/20260716_161541_MR-IN-anpr.jpg'
 */
export function buildImagePath(dateFolder, filename, baseDir = '/home/transjakarta/Pictures/view') {
  return `${baseDir}/${dateFolder}/${filename}`;
}

export default {
  fetchImage,
  fetchMultipleImages,
  buildImagePath,
};
