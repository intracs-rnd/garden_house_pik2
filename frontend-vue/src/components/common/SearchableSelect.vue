<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = defineProps({
  modelValue: { type: [String, Number, null], default: '' },
  /** Array of { value, label } option objects. */
  options: { type: Array, default: () => [] },
  placeholder: { type: String, default: 'Pilih...' },
  searchPlaceholder: { type: String, default: 'Cari...' },
  clearable: { type: Boolean, default: true },
  disabled: { type: Boolean, default: false },
  invalid: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const root = ref(null)
const searchInput = ref(null)
const open = ref(false)
const search = ref('')
const highlighted = ref(-1)

const selectedOption = computed(() =>
  props.options.find((o) => String(o.value) === String(props.modelValue)),
)

const selectedLabel = computed(() => selectedOption.value?.label ?? '')

const hasValue = computed(
  () => props.modelValue !== '' && props.modelValue !== null && props.modelValue !== undefined,
)

const filteredOptions = computed(() => {
  const term = search.value.trim().toLowerCase()
  if (!term) return props.options
  return props.options.filter((o) => String(o.label).toLowerCase().includes(term))
})

function openDropdown() {
  if (props.disabled) return
  open.value = true
  search.value = ''
  highlighted.value = filteredOptions.value.findIndex(
    (o) => String(o.value) === String(props.modelValue),
  )
  nextTick(() => searchInput.value?.focus())
}

function closeDropdown() {
  open.value = false
  search.value = ''
}

function toggle() {
  open.value ? closeDropdown() : openDropdown()
}

function selectOption(option) {
  emit('update:modelValue', option.value)
  closeDropdown()
}

function clearSelection() {
  emit('update:modelValue', null)
  closeDropdown()
}

function moveHighlight(step) {
  const count = filteredOptions.value.length
  if (!count) return
  highlighted.value = (highlighted.value + step + count) % count
}

function selectHighlighted() {
  const option = filteredOptions.value[highlighted.value]
  if (option) selectOption(option)
}

watch(search, () => {
  highlighted.value = filteredOptions.value.length ? 0 : -1
})

function handleClickOutside(event) {
  if (root.value && !root.value.contains(event.target)) closeDropdown()
}

onMounted(() => document.addEventListener('mousedown', handleClickOutside))
onBeforeUnmount(() => document.removeEventListener('mousedown', handleClickOutside))
</script>

<template>
  <div ref="root" class="ss" :class="{ 'ss--open': open, 'ss--disabled': disabled }">
    <button
      type="button"
      class="ss__control form-control"
      :class="{ 'is-invalid': invalid }"
      :disabled="disabled"
      @click="toggle"
    >
      <span class="ss__value" :class="{ 'ss__placeholder': !hasValue }">
        {{ hasValue ? selectedLabel : placeholder }}
      </span>
      <span
        v-if="clearable && hasValue"
        class="ss__clear"
        title="Hapus pilihan"
        @click.stop="clearSelection"
      >
        &times;
      </span>
      <span class="ss__arrow" aria-hidden="true"></span>
    </button>

    <div v-if="open" class="ss__dropdown">
      <div class="ss__search">
        <input
          ref="searchInput"
          v-model="search"
          type="text"
          class="ss__search-input"
          :placeholder="searchPlaceholder"
          @keydown.down.prevent="moveHighlight(1)"
          @keydown.up.prevent="moveHighlight(-1)"
          @keydown.enter.prevent="selectHighlighted"
          @keydown.esc.prevent="closeDropdown"
        />
      </div>
      <ul class="ss__list">
        <li
          v-for="(option, index) in filteredOptions"
          :key="option.value"
          class="ss__option"
          :class="{
            'ss__option--active': String(option.value) === String(modelValue),
            'ss__option--highlighted': index === highlighted,
          }"
          @mouseenter="highlighted = index"
          @click="selectOption(option)"
        >
          {{ option.label }}
        </li>
        <li v-if="!filteredOptions.length" class="ss__empty">Tidak ada hasil.</li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.ss {
  position: relative;
  width: 100%;
}

.ss__control {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  text-align: left;
  cursor: pointer;
  background: #fff;
}

.ss--disabled .ss__control {
  cursor: not-allowed;
  background: var(--color-bg);
}

.ss__value {
  flex: 1;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.ss__placeholder {
  color: var(--color-text-muted);
}

.ss__clear {
  color: var(--color-text-muted);
  font-size: 18px;
  line-height: 1;
  padding: 0 2px;
  border-radius: var(--radius-sm);
}

.ss__clear:hover {
  color: var(--color-danger);
}

.ss__arrow {
  width: 0;
  height: 0;
  border-left: 4px solid transparent;
  border-right: 4px solid transparent;
  border-top: 5px solid var(--color-text-muted);
  transition: transform 0.15s ease;
}

.ss--open .ss__arrow {
  transform: rotate(180deg);
}

.ss__dropdown {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  z-index: 30;
  background: #fff;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
  overflow: hidden;
}

.ss__search {
  padding: 8px;
  border-bottom: 1px solid var(--color-border);
}

.ss__search-input {
  width: 100%;
  padding: 8px 10px;
  font-size: 14px;
  color: var(--color-text);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  outline: none;
}

.ss__search-input:focus {
  border-color: var(--color-primary);
  box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
}

.ss__list {
  list-style: none;
  margin: 0;
  padding: 4px;
  max-height: 220px;
  overflow-y: auto;
}

.ss__option {
  padding: 8px 10px;
  font-size: 14px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.ss__option--highlighted {
  background: var(--color-primary-light);
}

.ss__option--active {
  color: var(--color-primary);
  font-weight: 600;
}

.ss__empty {
  padding: 10px;
  font-size: 13px;
  color: var(--color-text-muted);
  text-align: center;
}
</style>
