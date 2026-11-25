<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { Refresh, Search, UploadFilled } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from '@/plugins/element'
import { fetchFiles, removeFile, uploadFile } from '@/api/file'
import type { FileItem } from '@/api/file'

const loading = ref(false)
const uploading = ref(false)
const items = ref<FileItem[]>([])
const total = ref(0)
const fileInput = ref<HTMLInputElement | null>(null)

const query = reactive({
  page: 1,
  page_size: 10,
  keyword: '',
})

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await fetchFiles({
      page: query.page,
      page_size: query.page_size,
      keyword: query.keyword.trim() || undefined,
    })
    items.value = result.items
    total.value = result.total
  } catch {
    // 错误提示已由请求层统一处理
  } finally {
    loading.value = false
  }
}

function search(): void {
  query.page = 1
  void load()
}

function reset(): void {
  query.keyword = ''
  query.page = 1
  void load()
}

function pickFiles(): void {
  fileInput.value?.click()
}

async function onPicked(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  await doUpload(input.files)
  input.value = ''
}

async function onDrop(event: DragEvent): Promise<void> {
  await doUpload(event.dataTransfer?.files)
}

async function doUpload(list: FileList | null | undefined): Promise<void> {
  const files = Array.from(list ?? [])
  if (files.length === 0) {
    return
  }

  uploading.value = true
  let ok = 0
  try {
    for (const file of files) {
      try {
        await uploadFile(file)
        ok += 1
      } catch {
        // 单个失败继续下一个，错误提示已由请求层处理
      }
    }
    if (ok > 0) {
      ElMessage.success(`成功上传 ${ok} 个文件`)
      query.page = 1
      await load()
    }
  } finally {
    uploading.value = false
  }
}

function fullUrl(item: FileItem): string {
  return new URL(item.url, window.location.origin).toString()
}

async function copyLink(item: FileItem): Promise<void> {
  try {
    await navigator.clipboard.writeText(fullUrl(item))
    ElMessage.success('链接已复制')
  } catch {
    ElMessage.error('复制失败，请手动复制：' + fullUrl(item))
  }
}

function openUrl(item: FileItem): void {
  window.open(item.url, '_blank', 'noopener')
}

async function remove(item: FileItem): Promise<void> {
  await ElMessageBox.confirm(`确定删除「${item.name}」吗？物理文件也会一并删除。`, '删除文件', {
    type: 'warning',
    confirmButtonText: '删除',
    cancelButtonText: '取消',
  })
  await removeFile(item.id)
  ElMessage.success('已删除')
  if (items.value.length === 1 && query.page > 1) {
    query.page -= 1
  }
  await load()
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="file-page">
    <el-card shadow="never">
      <div class="toolbar">
        <div
          class="drop-zone"
          :class="{ uploading }"
          @click="pickFiles"
          @dragover.prevent
          @drop.prevent="onDrop"
        >
          <el-icon :size="26"><upload-filled /></el-icon>
          <div class="drop-text">
            {{ uploading ? '上传中…' : '点击选择或拖拽文件到此处' }}
          </div>
        </div>
        <input
          ref="fileInput"
          type="file"
          multiple
          hidden
          @change="onPicked"
        >

        <div class="filters">
          <el-input
            v-model="query.keyword"
            placeholder="按文件名搜索"
            clearable
            class="search-input"
            @keyup.enter="search"
          />
          <el-button type="primary" :icon="Search" @click="search">查询</el-button>
          <el-button :icon="Refresh" @click="reset">重置</el-button>
        </div>
      </div>

      <el-table v-loading="loading" :data="items" stripe>
        <el-table-column label="文件" min-width="260">
          <template #default="{ row }">
            <div class="file-cell">
              <img
                v-if="row.is_image"
                :src="row.url"
                class="thumb"
                alt=""
              >
              <div v-else class="thumb thumb-ext">{{ row.ext || 'file' }}</div>
              <div class="file-meta">
                <div class="file-name" :title="row.name">{{ row.name }}</div>
                <div class="file-sub">{{ row.mime }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="大小" width="100">
          <template #default="{ row }">{{ row.size_text }}</template>
        </el-table-column>
        <el-table-column label="驱动" width="80">
          <template #default="{ row }">
            <el-tag size="small" type="info">{{ row.driver }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="上传者" width="90">
          <template #default="{ row }">#{{ row.user_id }}</template>
        </el-table-column>
        <el-table-column label="上传时间" width="170">
          <template #default="{ row }">{{ row.created_at ?? '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="220" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="copyLink(row)">复制链接</el-button>
            <el-button link type="primary" @click="openUrl(row)">打开</el-button>
            <el-button v-permission="'file.delete'" link type="danger" @click="remove(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pager">
        <el-pagination
          v-model:current-page="query.page"
          v-model:page-size="query.page_size"
          :total="total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next"
          @current-change="load"
          @size-change="search"
        />
      </div>
    </el-card>
  </div>
</template>

<style scoped>
.file-page {
  padding: 16px;
}

.toolbar {
  display: flex;
  gap: 16px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}

.drop-zone {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 20px;
  border: 1px dashed var(--el-border-color);
  border-radius: 8px;
  cursor: pointer;
  color: var(--el-text-color-secondary);
  transition: border-color 0.2s, color 0.2s;
  min-width: 260px;
}

.drop-zone:hover,
.drop-zone.uploading {
  border-color: var(--el-color-primary);
  color: var(--el-color-primary);
}

.drop-text {
  font-size: 13px;
}

.filters {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
}

.search-input {
  width: 200px;
}

.file-cell {
  display: flex;
  align-items: center;
  gap: 10px;
}

.thumb {
  width: 40px;
  height: 40px;
  object-fit: cover;
  border-radius: 6px;
  border: 1px solid var(--el-border-color-lighter);
  flex-shrink: 0;
}

.thumb-ext {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  text-transform: uppercase;
  color: var(--el-text-color-secondary);
  background: var(--el-fill-color-light);
}

.file-meta {
  min-width: 0;
}

.file-name {
  font-size: 13px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 260px;
}

.file-sub {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.pager {
  margin-top: 14px;
  display: flex;
  justify-content: flex-end;
}
</style>
