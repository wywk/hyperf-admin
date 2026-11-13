<script setup lang="ts">
/**
 * 拖拽表单设计器。
 *
 * 左侧组件面板（可拖入/点击添加）、中间画布（拖拽排序、选中删除）、
 * 右侧属性面板。产出 JSON Schema，保存到后端 forms 表，
 * 渲染用 FormRenderer，提交数据走公开的 /form/{code}/submit。
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import FormRenderer from '@/components/FormRenderer.vue'
import { createForm, fetchForm, updateForm } from '@/api/forms'
import { ElMessage } from '@/plugins/element'
import type { FormDefinition, FormFieldSchema, FormFieldType, FormSchema } from '@/types'

const route = useRoute()
const router = useRouter()

/* ---------------- 画布数据 ---------------- */

const formId = ref(0)
const name = ref('')
const code = ref('')
const description = ref('')
const fields = ref<FormFieldSchema[]>([])
const selected = ref(-1)
const loading = ref(false)
const saving = ref(false)

const current = computed<FormFieldSchema | null>(() => fields.value[selected.value] ?? null)

/* ---------------- 组件面板 ---------------- */

interface PaletteItem {
  type: FormFieldType
  label: string
  icon: string
}

const PALETTE: { group: string; items: PaletteItem[] }[] = [
  {
    group: '基础控件',
    items: [
      { type: 'input', label: '单行文本', icon: '✏️' },
      { type: 'textarea', label: '多行文本', icon: '📝' },
      { type: 'number', label: '数字', icon: '🔢' },
      { type: 'switch', label: '开关', icon: '🎚️' },
    ],
  },
  {
    group: '选择控件',
    items: [
      { type: 'select', label: '下拉选择', icon: '🔽' },
      { type: 'radio', label: '单选', icon: '🔘' },
      { type: 'checkbox', label: '多选', icon: '☑️' },
    ],
  },
  {
    group: '时间',
    items: [{ type: 'date', label: '日期', icon: '📅' }],
  },
]

/** 拖拽中转：{ from: 'palette', type } 或 { from: 'canvas', index } */
let dragPayload: { from: 'palette'; type: FormFieldType } | { from: 'canvas'; index: number } | null = null

function newField(type: FormFieldType): FormFieldSchema {
  const base: Record<FormFieldType, FormFieldSchema> = {
    input: { field: '', label: '', type, placeholder: '' },
    textarea: { field: '', label: '', type, placeholder: '' },
    number: { field: '', label: '', type },
    switch: { field: '', label: '', type },
    select: { field: '', label: '', type, options: [] },
    radio: { field: '', label: '', type, options: [] },
    checkbox: { field: '', label: '', type, options: [] },
    date: { field: '', label: '', type },
  }
  return JSON.parse(JSON.stringify(base[type]))
}

function addField(type: FormFieldType): void {
  fields.value.push(newField(type))
  selected.value = fields.value.length - 1
}

function onPaletteDragStart(type: FormFieldType, event: DragEvent): void {
  dragPayload = { from: 'palette', type }
  event.dataTransfer?.setData('text/plain', type)
}

function onCanvasDragStart(index: number, event: DragEvent): void {
  dragPayload = { from: 'canvas', index }
  event.dataTransfer?.setData('text/plain', String(index))
}

function onDrop(atIndex?: number): void {
  if (!dragPayload) {
    return
  }

  if (dragPayload.from === 'palette') {
    fields.value.push(newField(dragPayload.type))
    selected.value = fields.value.length - 1
  } else {
    // 画布内重排
    const from = dragPayload.index
    let to = atIndex ?? fields.value.length - 1
    if (to > from) {
      to -= 1
    }
    const [moved] = fields.value.splice(from, 1)
    fields.value.splice(to, 0, moved)
    selected.value = to
  }
  dragPayload = null
}

function removeField(index: number): void {
  if (selected.value === index) {
    selected.value = -1
  } else if (selected.value > index) {
    selected.value -= 1
  }
  fields.value.splice(index, 1)
}

function move(index: number, offset: number): void {
  const to = index + offset
  if (to < 0 || to >= fields.value.length) {
    return
  }
  const [moved] = fields.value.splice(index, 1)
  fields.value.splice(to, 0, moved)
  selected.value = to
}

/* ---------------- 属性面板 ---------------- */

const NEEDS_OPTIONS: FormFieldType[] = ['select', 'radio', 'checkbox']
const needOptions = computed(() => Boolean(current.value && NEEDS_OPTIONS.includes(current.value.type)))
const needRange = computed(() => Boolean(current.value && current.value.type === 'number'))

/** 选项用每行一个的方式编辑 */
const optionsText = computed({
  get: () => (current.value?.options ?? []).join('\n'),
  set: (value: string) => {
    if (current.value) {
      current.value.options = value
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean)
    }
  },
})

/* ---------------- 校验与保存 ---------------- */

function validateSchema(): boolean {
  if (!name.value.trim()) {
    ElMessage.warning('请填写表单名称')
    return false
  }
  if (!/^[a-z][a-z0-9_-]*$/.test(code.value.trim())) {
    ElMessage.warning('表单标识必须是小写字母开头的字母/数字/下划线/中划线')
    return false
  }
  if (fields.value.length === 0) {
    ElMessage.warning('至少拖一个字段到画布')
    return false
  }

  const seen = new Set<string>()
  for (const field of fields.value) {
    if (!field.label.trim()) {
      ElMessage.warning(`有字段没填显示名称（label）`)
      return false
    }
    if (!/^[a-z][a-z0-9_]*$/.test(field.field.trim())) {
      ElMessage.warning(`字段「${field.label}」的字段名必须是小写字母开头的字母/数字/下划线`)
      return false
    }
    if (seen.has(field.field)) {
      ElMessage.warning(`字段名「${field.field}」重复`)
      return false
    }
    seen.add(field.field)
    if (NEEDS_OPTIONS.includes(field.type) && (field.options?.length ?? 0) === 0) {
      ElMessage.warning(`字段「${field.label}」需要至少一个选项`)
      return false
    }
  }

  return true
}

async function save(): Promise<void> {
  if (!validateSchema()) {
    return
  }

  const payload = {
    name: name.value.trim(),
    code: code.value.trim(),
    description: description.value.trim(),
    schema: { fields: fields.value } satisfies FormSchema,
  }

  saving.value = true
  try {
    if (formId.value > 0) {
      await updateForm(formId.value, payload)
      ElMessage.success('已保存')
    } else {
      const result = await createForm(payload)
      formId.value = result.id
      ElMessage.success('已创建')
      history.replaceState({}, '', `/tool/forms/designer?id=${result.id}`)
    }
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    saving.value = false
  }
}

/* ---------------- 预览 ---------------- */

const previewVisible = ref(false)
const previewModel = ref<Record<string, unknown>>({})

function openPreview(): void {
  if (fields.value.length === 0) {
    ElMessage.warning('画布还是空的，先拖几个字段进来')
    return
  }
  previewModel.value = {}
  previewVisible.value = true
}

async function onPreviewSubmit(): Promise<void> {
  if (formId.value === 0) {
    ElMessage.info('预览模式下先保存表单才能提交真实数据')
    previewVisible.value = false
    return
  }
  ElMessage.success(`已按 ${code.value} 的 Schema 校验通过（提交接口：POST /api/form/${code.value}/submit）`)
  previewVisible.value = false
}

/* ---------------- Schema 导入导出 ---------------- */

const jsonVisible = ref(false)
const jsonText = ref('')

function openJson(): void {
  jsonText.value = JSON.stringify({ fields: fields.value }, null, 2)
  jsonVisible.value = true
}

function applyJson(): void {
  try {
    const parsed = JSON.parse(jsonText.value) as FormSchema
    if (!Array.isArray(parsed.fields)) {
      ElMessage.error('schema.fields 必须是数组')
      return
    }
    fields.value = parsed.fields
    jsonVisible.value = false
    selected.value = -1
    ElMessage.success(`已导入 ${parsed.fields.length} 个字段`)
  } catch (error) {
    ElMessage.error('JSON 解析失败：' + (error instanceof Error ? error.message : String(error)))
  }
}

/* ---------------- 编辑加载 ---------------- */

onMounted(async () => {
  const id = Number(route.query.id ?? 0)
  if (id <= 0) {
    return
  }

  loading.value = true
  try {
    const form: FormDefinition = await fetchForm(id)
    formId.value = form.id
    name.value = form.name
    code.value = form.code
    description.value = form.description
    fields.value = form.schema?.fields ?? []
  } catch {
    router.replace('/tool/forms')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div v-loading="loading" class="designer">
    <!-- 顶部工具栏 -->
    <div class="toolbar">
      <el-input v-model="name" placeholder="表单名称，如：入职登记表" maxlength="64" style="width: 220px" />
      <el-input v-model="code" placeholder="标识，如：onboard-survey" maxlength="64" style="width: 220px">
        <template #prepend>/api/form/</template>
      </el-input>
      <el-input v-model="description" placeholder="说明（可选）" maxlength="255" style="width: 220px" />
      <el-space style="margin-left: auto">
        <el-button @click="openJson">Schema</el-button>
        <el-button @click="openPreview">预览</el-button>
        <el-button v-permission="'form.edit'" type="primary" :loading="saving" @click="save">保存</el-button>
        <el-button @click="router.back()">返回</el-button>
      </el-space>
    </div>

    <div class="workspace">
      <!-- 左：组件面板 -->
      <div class="palette">
        <div class="panel-title">组件</div>
        <div v-for="group in PALETTE" :key="group.group" class="palette-group">
          <div class="group-name">{{ group.group }}</div>
          <div class="palette-grid">
            <div
              v-for="item in group.items"
              :key="item.type"
              class="palette-item"
              draggable="true"
              @dragstart="onPaletteDragStart(item.type, $event)"
              @click="addField(item.type)"
            >
              <span class="palette-icon">{{ item.icon }}</span>
              <span>{{ item.label }}</span>
            </div>
          </div>
        </div>
        <div class="palette-tip">拖到画布，或点击直接添加</div>
      </div>

      <!-- 中：画布 -->
      <div class="canvas-wrap">
        <div class="panel-title">画布</div>
        <div
          class="canvas"
          @dragover.prevent
          @drop.prevent="onDrop(fields.length)"
        >
          <el-empty
            v-if="fields.length === 0"
            description="把左侧组件拖到这里"
            :image-size="80"
          />
          <div
            v-for="(field, index) in fields"
            :key="field.field + index"
            class="canvas-item"
            :class="{ selected: selected === index }"
            draggable="true"
            @dragstart="onCanvasDragStart(index, $event)"
            @dragover.prevent.stop
            @drop.prevent.stop="onDrop(index)"
            @click="selected = index"
          >
            <div class="item-head">
              <span class="item-label">{{ field.label || '未命名字段' }}</span>
              <el-tag size="small" type="info">{{ field.type }}</el-tag>
              <el-tag v-if="field.required" size="small" type="danger">必填</el-tag>
              <span class="item-actions" @click.stop>
                <el-button link size="small" :disabled="index === 0" @click="move(index, -1)">↑</el-button>
                <el-button link size="small" :disabled="index === fields.length - 1" @click="move(index, 1)">↓</el-button>
                <el-button link size="small" type="danger" @click="removeField(index)">删除</el-button>
              </span>
            </div>
            <div class="item-field">field: {{ field.field || '（未设置）' }}</div>
          </div>
        </div>
      </div>

      <!-- 右：属性面板 -->
      <div class="props">
        <div class="panel-title">字段属性</div>
        <template v-if="current">
          <el-form label-width="72px" size="default">
            <el-form-item label="显示名称">
              <el-input v-model="current.label" maxlength="32" placeholder="如：姓名" />
            </el-form-item>
            <el-form-item label="字段名">
              <el-input v-model="current.field" maxlength="64" placeholder="英文，如 name" />
            </el-form-item>
            <el-form-item label="控件类型">
              <el-tag>{{ current.type }}</el-tag>
            </el-form-item>
            <el-form-item label="必填">
              <el-switch v-model="current.required" />
            </el-form-item>
            <el-form-item v-if="['input', 'textarea', 'select', 'date'].includes(current.type)" label="提示文字">
              <el-input v-model="current.placeholder" maxlength="64" placeholder="占位提示" />
            </el-form-item>
            <template v-if="needRange">
              <el-form-item label="最小值">
                <el-input-number v-model="current.min" :max="999999999" />
              </el-form-item>
              <el-form-item label="最大值">
                <el-input-number v-model="current.max" :max="999999999" />
              </el-form-item>
            </template>
            <el-form-item v-if="needOptions" label="选项">
              <el-input
                :model-value="optionsText"
                type="textarea"
                :rows="5"
                placeholder="每行一个选项"
                @update:model-value="optionsText = $event"
              />
            </el-form-item>
          </el-form>
        </template>
        <el-empty v-else description="点击画布中的字段编辑属性" :image-size="60" />
      </div>
    </div>

    <!-- 预览弹窗 -->
    <el-dialog v-model="previewVisible" title="表单预览（FormRenderer 实时渲染）" width="680px">
      <FormRenderer
        :schema="{ fields }"
        v-model="previewModel"
        @submit="onPreviewSubmit"
      />
      <template #footer>
        <el-button @click="previewVisible = false">关闭</el-button>
      </template>
    </el-dialog>

    <!-- Schema 导入导出 -->
    <el-dialog v-model="jsonVisible" title="JSON Schema（可复制导出，也可粘贴导入）" width="680px">
      <el-input v-model="jsonText" type="textarea" :rows="18" spellcheck="false" class="mono" />
      <template #footer>
        <el-button @click="jsonVisible = false">取消</el-button>
        <el-button type="primary" @click="applyJson">导入到画布</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.designer {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

.toolbar {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  background: var(--app-surface, #fff);
  border-bottom: 1px solid var(--app-border, #e4e7ed);
  border-radius: 8px 8px 0 0;
}

.workspace {
  display: grid;
  grid-template-columns: 230px 1fr 280px;
  gap: 12px;
  flex: 1;
  min-height: 480px;
  padding: 12px;
  background: var(--app-surface, #fff);
  border-radius: 0 0 8px 8px;
}

.panel-title {
  margin-bottom: 10px;
  font-size: 13px;
  font-weight: 600;
  color: var(--app-muted, #909399);
}

.palette-group + .palette-group {
  margin-top: 14px;
}

.group-name {
  margin-bottom: 6px;
  font-size: 12px;
  color: var(--app-muted, #909399);
}

.palette-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;
}

.palette-item {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 10px;
  font-size: 12px;
  cursor: grab;
  user-select: none;
  border: 1px solid var(--app-border, #e4e7ed);
  border-radius: 6px;
  transition: all 0.15s;
}

.palette-item:hover {
  border-color: var(--el-color-primary);
  color: var(--el-color-primary);
}

.palette-icon {
  font-size: 14px;
}

.palette-tip {
  margin-top: 16px;
  font-size: 12px;
  color: var(--app-muted, #909399);
}

.canvas {
  display: flex;
  flex-direction: column;
  gap: 8px;
  height: 100%;
  padding: 12px;
  overflow-y: auto;
  background: var(--app-bg, #f5f7fa);
  border: 1px dashed var(--app-border, #c0c4cc);
  border-radius: 6px;
}

.canvas-item {
  padding: 10px 12px;
  cursor: grab;
  background: #fff;
  border: 1px solid var(--app-border, #e4e7ed);
  border-radius: 6px;
  transition: border-color 0.15s, box-shadow 0.15s;
}

.canvas-item.selected {
  border-color: var(--el-color-primary);
  box-shadow: 0 0 0 2px var(--el-color-primary-light-8, rgba(64, 158, 255, 0.12));
}

.item-head {
  display: flex;
  align-items: center;
  gap: 6px;
}

.item-label {
  font-weight: 600;
}

.item-actions {
  display: inline-flex;
  gap: 0;
  margin-left: auto;
}

.item-field {
  margin-top: 4px;
  font-size: 12px;
  color: var(--app-muted, #909399);
}

.props {
  overflow-y: auto;
}

.mono :deep(textarea) {
  font-family: 'SF Mono', Menlo, Consolas, monospace;
  font-size: 12px;
}
</style>
