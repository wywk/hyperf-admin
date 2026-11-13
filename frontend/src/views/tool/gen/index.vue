<script setup lang="ts">
/**
 * 代码生成器页面：
 * 选表 → 自动带出字段配置（可调整控件/列表/搜索/表单勾选）→ 预览 → 生成。
 * 生成会写入前后端代码文件并自动注册菜单与按钮权限。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import {
  fetchColumns,
  fetchTables,
  generateCode,
  previewCode,
  type ColumnInfo,
  type FieldConfig,
  type GenerateConfig,
  type TableInfo,
} from '@/api/codegen'
import { ElMessage, ElMessageBox } from '@/plugins/element'
import type { FormFieldType } from '@/types'

/* ---------------- 表列表 ---------------- */

const loadingTables = ref(false)
const tables = ref<TableInfo[]>([])
const keyword = ref('')

const filteredTables = computed(() =>
  tables.value.filter((t) => !t.is_system && (keyword.value === '' || t.name.includes(keyword.value))),
)

async function loadTables(): Promise<void> {
  loadingTables.value = true
  try {
    tables.value = (await fetchTables()).items
  } catch {
    // 错误由请求层统一提示
  } finally {
    loadingTables.value = false
  }
}

onMounted(loadTables)

/* ---------------- 配置 ---------------- */

const selectedTable = ref('')
const loadingColumns = ref(false)
const fields = ref<FieldConfig[]>([])

const config = reactive({
  module: '',
  controller: '',
  menu_name: '',
  menu_path: '',
  menu_icon: 'Document',
})

const FORM_TYPES: { value: FormFieldType; label: string }[] = [
  { value: 'input', label: '单行文本' },
  { value: 'textarea', label: '多行文本' },
  { value: 'number', label: '数字' },
  { value: 'select', label: '下拉' },
  { value: 'radio', label: '单选' },
  { value: 'checkbox', label: '多选' },
  { value: 'switch', label: '开关' },
  { value: 'date', label: '日期' },
]

/** MySQL 类型 → 控件类型初猜 */
function guessFormType(dataType: string): FieldConfig['form_type'] {
  if (['tinyint'].includes(dataType)) {
    return 'switch'
  }
  if (['int', 'integer', 'bigint', 'smallint', 'decimal', 'double', 'float'].includes(dataType)) {
    return 'number'
  }
  if (['date', 'datetime', 'timestamp'].includes(dataType)) {
    return 'date'
  }
  if (['text', 'mediumtext', 'longtext'].includes(dataType)) {
    return 'textarea'
  }
  return 'input'
}

async function selectTable(table: TableInfo): Promise<void> {
  selectedTable.value = table.name
  loadingColumns.value = true
  config.module = table.bare.replace(/^(t_|tbl_)/, '')
  config.controller = config.module
    .split(/[_-]/)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
  config.menu_name = table.comment || config.controller
  config.menu_path = config.module
  fields.value = []

  try {
    const columns: ColumnInfo[] = (await fetchColumns(table.name)).items.filter(
      (column) => !['id', 'created_at', 'updated_at', 'deleted_at'].includes(column.name),
    )
    fields.value = columns.map((column) => ({
      name: column.name,
      label: column.comment || column.name,
      form_type: guessFormType(column.data_type),
      required: !column.nullable && column.default === null,
      list: true,
      search: false,
      form: true,
      options: [],
    }))
  } catch {
    // 错误由请求层统一提示
  } finally {
    loadingColumns.value = false
  }
}

/** 选项编辑：逗号分隔 */
function parseOptions(row: FieldConfig): string {
  return row.options.join(',')
}

function applyOptions(row: FieldConfig, value: string): void {
  row.options = value
    .split(/[,，]/)
    .map((item) => item.trim())
    .filter(Boolean)
}

function buildConfig(): GenerateConfig {
  return {
    table: selectedTable.value,
    module: config.module.trim(),
    controller: config.controller.trim(),
    menu: {
      name: config.menu_name.trim(),
      path: config.menu_path.trim(),
      icon: config.menu_icon.trim(),
    },
    fields: fields.value,
  }
}

const configErrors = computed<string[]>(() => {
  const errors: string[] = []
  if (!selectedTable.value) {
    errors.push('先选择一张数据表')
  }
  if (!/^[a-z][a-z0-9_-]*$/.test(config.module.trim())) {
    errors.push('模块标识必须是小写字母开头（字母/数字/下划线/中划线）')
  }
  if (fields.value.length === 0) {
    errors.push('没有可生成的字段')
  }
  const withOptions = fields.value.filter((f) => ['select', 'radio', 'checkbox'].includes(f.form_type))
  for (const field of withOptions) {
    if (field.options.length === 0) {
      errors.push(`字段「${field.label}」是选择类控件，需要填选项（逗号分隔）`)
    }
  }
  return errors
})

/* ---------------- 预览 / 生成 ---------------- */

const previewVisible = ref(false)
const previewFiles = ref<{ path: string; content: string }[]>([])
const activeFile = ref('')

async function doPreview(): Promise<void> {
  if (configErrors.value.length > 0) {
    ElMessage.warning(configErrors.value[0])
    return
  }
  try {
    const result = await previewCode(buildConfig())
    previewFiles.value = Object.entries(result.files).map(([path, content]) => ({ path, content }))
    activeFile.value = previewFiles.value[0]?.path ?? ''
    previewVisible.value = true
  } catch {
    // 错误由请求层统一提示
  }
}

const generating = ref(false)
const generatedFiles = ref<string[]>([])
const resultVisible = ref(false)

async function doGenerate(): Promise<void> {
  if (configErrors.value.length > 0) {
    ElMessage.warning(configErrors.value[0])
    return
  }

  try {
    await ElMessageBox.confirm(
      '将写入后端 Model/Service/Controller、路由块，前端 api/页面/路由块，并注册菜单权限（重复生成会覆盖同模块旧代码）。继续？',
      '执行生成',
      { type: 'warning', confirmButtonText: '生成', cancelButtonText: '取消' },
    )
  } catch {
    return
  }

  generating.value = true
  try {
    const result = await generateCode(buildConfig())
    generatedFiles.value = result.files
    resultVisible.value = true
    ElMessage.success('生成完成')
  } catch {
    // 错误由请求层统一提示
  } finally {
    generating.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h2 class="page-title">代码生成</h2>
        <p class="page-desc">选表 → 配字段 → 一键生成前后端 CRUD 代码 + 菜单权限（开发环境工具）。</p>
      </div>
      <el-space>
        <el-button @click="doPreview" :disabled="!selectedTable">预览代码</el-button>
        <el-button v-permission="'gen.create'" type="primary" :loading="generating" @click="doGenerate" :disabled="!selectedTable">
          执行生成
        </el-button>
      </el-space>
    </div>

    <div class="gen-layout">
      <!-- 左：数据表 -->
      <el-card shadow="never" class="table-list">
        <template #header>数据表</template>
        <el-input v-model="keyword" placeholder="搜索表名" clearable size="small" class="search" />
        <div v-loading="loadingTables" class="tables">
          <div
            v-for="table in filteredTables"
            :key="table.name"
            class="table-item"
            :class="{ active: selectedTable === table.name }"
            @click="selectTable(table)"
          >
            <span class="t-name">{{ table.name }}</span>
            <el-tag v-if="!table.is_prefixed" size="small" type="warning" effect="plain">无前缀</el-tag>
            <span class="t-comment">{{ table.comment }}</span>
          </div>
          <el-empty v-if="filteredTables.length === 0" description="没有业务表" :image-size="60" />
        </div>
      </el-card>

      <!-- 右：配置 -->
      <el-card shadow="never" class="config">
        <template #header>生成配置 {{ selectedTable ? `· ${selectedTable}` : '' }}</template>

        <div v-if="!selectedTable" class="placeholder">
          <el-empty description="从左侧选择一张表开始" :image-size="90" />
        </div>

        <template v-else>
          <el-form inline label-width="92px" class="base-config">
            <el-form-item label="模块标识">
              <el-input v-model="config.module" style="width: 180px" placeholder="product" />
            </el-form-item>
            <el-form-item label="类名前缀">
              <el-input v-model="config.controller" style="width: 180px" placeholder="Product" />
            </el-form-item>
            <el-form-item label="菜单名称">
              <el-input v-model="config.menu_name" style="width: 180px" />
            </el-form-item>
            <el-form-item label="菜单路由">
              <el-input v-model="config.menu_path" style="width: 180px" />
            </el-form-item>
            <el-form-item label="菜单图标">
              <el-input v-model="config.menu_icon" style="width: 180px" placeholder="Element Plus 图标名" />
            </el-form-item>
          </el-form>

          <el-alert
            v-if="configErrors.length > 0"
            type="warning"
            :closable="false"
            class="config-alert"
          >
            <div v-for="(error, i) in configErrors" :key="i">{{ i + 1 }}. {{ error }}</div>
          </el-alert>

          <el-table v-loading="loadingColumns" :data="fields" size="small" max-height="480">
            <el-table-column prop="name" label="字段" width="150" show-overflow-tooltip />
            <el-table-column label="显示名" width="150">
              <template #default="{ row }">
                <el-input v-model="row.label" size="small" maxlength="32" />
              </template>
            </el-table-column>
            <el-table-column label="控件" width="120">
              <template #default="{ row }">
                <el-select v-model="row.form_type" size="small">
                  <el-option v-for="t in FORM_TYPES" :key="t.value" :label="t.label" :value="t.value" />
                </el-select>
              </template>
            </el-table-column>
            <el-table-column label="选项（逗号分隔）" min-width="170">
              <template #default="{ row }">
                <el-input
                  v-if="['select', 'radio', 'checkbox'].includes(row.form_type)"
                  :model-value="parseOptions(row)"
                  size="small"
                  placeholder="如：待处理,已完成"
                  @update:model-value="applyOptions(row, $event)"
                />
                <span v-else class="muted">-</span>
              </template>
            </el-table-column>
            <el-table-column label="必填" width="60" align="center">
              <template #default="{ row }">
                <el-switch v-model="row.required" size="small" />
              </template>
            </el-table-column>
            <el-table-column label="列表" width="60" align="center">
              <template #default="{ row }">
                <el-switch v-model="row.list" size="small" />
              </template>
            </el-table-column>
            <el-table-column label="搜索" width="60" align="center">
              <template #default="{ row }">
                <el-switch v-model="row.search" size="small" />
              </template>
            </el-table-column>
            <el-table-column label="表单" width="60" align="center">
              <template #default="{ row }">
                <el-switch v-model="row.form" size="small" />
              </template>
            </el-table-column>
          </el-table>
        </template>
      </el-card>
    </div>

    <!-- 预览 -->
    <el-dialog v-model="previewVisible" title="生成代码预览（不会写文件）" width="860px" top="5vh">
      <el-tabs v-model="activeFile">
        <el-tab-pane v-for="file in previewFiles" :key="file.path" :label="file.path" :name="file.path">
          <pre class="code">{{ file.content }}</pre>
        </el-tab-pane>
      </el-tabs>
    </el-dialog>

    <!-- 生成结果 -->
    <el-dialog v-model="resultVisible" title="生成完成 · 已写入的文件" width="560px">
      <ul class="file-list">
        <li v-for="file in generatedFiles" :key="file">
          <span class="mono">{{ file }}</span>
        </li>
      </ul>
      <el-alert type="info" :closable="false">
        重启后端容器（docker restart hyperf）并刷新前端后，左侧菜单会出现新页面；菜单权限已自动授予 admin。
      </el-alert>
      <template #footer>
        <el-button type="primary" @click="resultVisible = false">知道了</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 14px;
}

.page-title {
  margin: 0 0 4px;
  font-size: 20px;
}

.page-desc {
  margin: 0;
  font-size: 13px;
  color: var(--app-muted);
}

.gen-layout {
  display: grid;
  grid-template-columns: 300px 1fr;
  gap: 12px;
}

.search {
  margin-bottom: 10px;
}

.tables {
  max-height: 560px;
  overflow-y: auto;
}

.table-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 8px 10px;
  border-radius: 6px;
  cursor: pointer;
  transition: background 0.15s;
}

.table-item:hover {
  background: var(--el-fill-color-light);
}

.table-item.active {
  background: var(--el-color-primary-light-9);
}

.t-name {
  font-family: 'SF Mono', Menlo, Consolas, monospace;
  font-size: 13px;
  font-weight: 600;
}

.t-comment {
  font-size: 12px;
  color: var(--app-muted);
}

.base-config {
  margin-bottom: 4px;
}

.config-alert {
  margin-bottom: 12px;
}

.placeholder {
  padding: 60px 0;
}

.code {
  max-height: 480px;
  overflow: auto;
  margin: 0;
  padding: 12px;
  font-family: 'SF Mono', Menlo, Consolas, monospace;
  font-size: 12px;
  line-height: 1.6;
  background: var(--el-fill-color-lighter);
  border-radius: 6px;
  white-space: pre;
}

.muted {
  color: var(--app-muted);
}
</style>
