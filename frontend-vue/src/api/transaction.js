import api from './axios'
import axios from 'axios'

/**
 * Transaction API
 * Handles transaction operations including plate number validation,
 * image fetching, and status updates
 */

const transactionApi = {
  /**
   * Helper: Convert ArrayBuffer to Base64 string
   * @param {ArrayBuffer} buffer
   * @returns {string} Base64 string
   */
  _arrayBufferToBase64(buffer) {
    let binary = ''
    const bytes = new Uint8Array(buffer)
    const len = bytes.byteLength
    for (let i = 0; i < len; i++) {
      binary += String.fromCharCode(bytes[i])
    }
    return btoa(binary)
  },

  /**
   * Validate plate number and get transaction data
   * @param {string} plateNumber - The plate number to validate
   * @returns {Promise} Transaction data if valid
   */
  async validatePlate(plateNumber) {
    const response = await api.get('/transactions/validate', {
      params: { plate_number: plateNumber }
    })
    return response.data
  },

  /**
   * Fetch image from external ANPR API
   * @param {string} imagePath - Path to the image on ANPR server
   * @returns {Promise} Image data or URL
   */
  async fetchImage(imagePath) {
    console.log('📥 Original image path:', imagePath)

    // Prepare multiple path variations to try
    const pathVariations = []

    // Handle /var/www/html/api-mr-pik2/ prefix
    if (imagePath.includes('/var/www/html/api-mr-pik2/')) {
      // Try 1: Convert to /home/transjakarta/ (likely to work based on Postman test)
      const transjakartaPath = imagePath.replace('/var/www/html/api-mr-pik2/', '/home/transjakarta/')
      pathVariations.push({ path: transjakartaPath, label: 'transjakarta path' })

      // Try 2: Remove prefix completely
      const relativePath = imagePath.replace('/var/www/html/api-mr-pik2', '')
      pathVariations.push({ path: relativePath, label: 'relative path' })

      // Try 3: Original path
      pathVariations.push({ path: imagePath, label: 'original path' })
    }
    // Handle /var/www/html/ prefix
    else if (imagePath.includes('/var/www/html/')) {
      const cleanPath = imagePath.replace('/var/www/html/', '/')
      pathVariations.push({ path: cleanPath, label: 'cleaned html path' })
      pathVariations.push({ path: imagePath, label: 'original path' })
    }
    // Handle /home/transjakarta/ prefix (from ANPR system - already working)
    else if (imagePath.includes('/home/transjakarta/')) {
      pathVariations.push({ path: imagePath, label: 'original ANPR path' })
    }
    // Fallback: use original
    else {
      pathVariations.push({ path: imagePath, label: 'original path' })
    }

    console.log('🔄 Will try', pathVariations.length, 'path variations:', pathVariations.map(p => p.label))

    // Try each path variation until one succeeds
    let lastError = null
    for (let i = 0; i < pathVariations.length; i++) {
      const { path: apiPath, label } = pathVariations[i]

      try {
        console.log(`🌐 [Attempt ${i + 1}/${pathVariations.length}] Trying ${label}:`, apiPath)

        // Try to get response as arraybuffer first to avoid encoding issues
        const uploadsApiUrl = import.meta.env.VITE_UPLOADS_API_URL
        const response = await axios.post(uploadsApiUrl, {
          path: apiPath
        }, {
          timeout: 15000,
          responseType: 'arraybuffer'  // Use arraybuffer to handle binary data properly
        })

        console.log(`✅ [Attempt ${i + 1}] SUCCESS with ${label}!`)
        console.log('📦 Response type:', typeof response.data, '| Byte length:', response.data.byteLength || response.data.length)

        // Convert arraybuffer to base64
        const base64Data = this._arrayBufferToBase64(response.data)
        console.log('📦 Converted to base64, length:', base64Data.length, '| Preview:', base64Data.substring(0, 50))

        // Return success immediately
        if (base64Data && base64Data.length > 100) {
          console.log('✅ Image fetched successfully with', label)
          return {
            success: true,
            path: imagePath,
            url: null,
            base64: base64Data,
            usedPath: apiPath
          }
        }

        // Response received but data too short, try next variation
        console.log(`⚠️ [Attempt ${i + 1}] Response OK but data too short, trying next...`)

      } catch (error) {
        lastError = error
        console.error(`❌ [Attempt ${i + 1}] Failed with ${label}:`, error.message)

        // If this is not the last variation, continue to next
        if (i < pathVariations.length - 1) {
          console.log(`🔄 Trying next path variation...`)
          continue
        }
      }
    }

    // All attempts failed
    console.error('❌ All path variations failed for:', imagePath)
    console.error('❌ Last error:', lastError?.message)

    // Generate fallback URLs
    const uploadsBaseUrl = import.meta.env.VITE_UPLOADS_API_URL?.replace('/api/uploads', '') || ''
    const fallbackUrls = []
    if (imagePath.includes('/storage/')) {
      const storageIndex = imagePath.indexOf('/storage/')
      const relativePath = imagePath.substring(storageIndex)
      fallbackUrls.push(`${uploadsBaseUrl}${relativePath}`)
    }
    if (imagePath.includes('/api-mr-pik2/')) {
      const cleanPath = imagePath.replace('/var/www/html/api-mr-pik2', '')
      fallbackUrls.push(`${uploadsBaseUrl}${cleanPath}`)
    }
    if (imagePath.includes('/home/transjakarta/')) {
      fallbackUrls.push(imagePath)
    }

    return {
      success: false,
      path: imagePath,
      url: fallbackUrls[0] || null,
      error: lastError?.message || 'Failed to fetch image',
      errorDetails: lastError?.response ? lastError.response.data : null,
      fallbackUrls: fallbackUrls,
      attemptedPaths: pathVariations.map(p => p.path)
    }
  },

  /**
   * Update transaction status to COMPLETED
   * @param {string} transactionId - The transaction ID to update
   * @returns {Promise} Update result
   */
  async completeTransaction(transactionId) {
    const response = await api.patch(`/transactions/${transactionId}/complete`)
    return response.data
  },

  /**
   * Get transaction by plate number with status ACTIVE
   * @param {string} plateNumber - The plate number to search
   * @returns {Promise} Transaction data
   */
  async getActiveTransaction(plateNumber) {
    const response = await api.get('/transactions/active', {
      params: { plate_number: plateNumber }
    })
    return response.data
  }
}

export default transactionApi
