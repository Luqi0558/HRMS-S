import { computed, ref, watch } from 'vue'

export function useTableControls(source, searchText, pageSize = 10) {
  const query = ref('')
  const limit = ref(pageSize)

  const filteredRows = computed(() => {
    const rows = typeof source === 'function' ? source() : source.value
    const term = query.value.trim().toLowerCase()
    if (!term) return rows
    return rows.filter(row => searchText(row).toLowerCase().includes(term))
  })

  const visibleRows = computed(() => filteredRows.value.slice(0, limit.value))
  const hasMoreRows = computed(() => limit.value < filteredRows.value.length)

  function loadMoreRows(event) {
    const target = event.target
    if (!target || !hasMoreRows.value) return
    const nearBottom = target.scrollTop + target.clientHeight >= target.scrollHeight - 24
    if (nearBottom) limit.value = Math.min(limit.value + pageSize, filteredRows.value.length)
  }

  watch(query, () => {
    limit.value = pageSize
  })

  return {
    query,
    filteredRows,
    visibleRows,
    hasMoreRows,
    loadMoreRows
  }
}