<script setup lang="ts">
/**
 * JSON Schema 表单渲染引擎。
 *
 * 传入 schema.fields + modelValue 即可渲染完整表单（Element Plus + 校验规则），
 * 设计器预览、填表页、生成的代码都可以复用。
 */
import { computed, ref } from 'vue'
import { ElMessage } from '@/plugins/element'
import type { FormFieldSchema, FormSchema } from '@/types'

interface Props {
  schema: FormSchema
  modelValue?: Record<string, unknown>
  /** 渲染为只读（预览模式只看不填） */
  disabled?: boolean
  labelWidth?: string
}

const props = defineProps<Props>()

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
  submit: [value: Record<string, unknown>]
}>()

const formRef = ref()
const model = computed<Record<string, unknown>>(() => props.modelValue ?? {})
const disabled = computed<boolean>(() => props.disabled ?? false)
const labelWidth = computed<string>(() => props.labelWidth ?? '110px')

/** 根据字段定义推导初始值 */
function initialValue(field: FormFieldSchema): unknown {
  if (field.default !== undefined && field.default !== null && field.default !== '') {
    return field.default
  }
  switch (field.type) {
    case 'checkbox':
      return []
    case 'switch':
      return false
    case 'number':
      return field.min ?? 0
    default:
      return ''
  }
}

/** 校验规则 */
const rules = computed(() => {
  const result: Record<string, unknown[]> = {}
  for (const field of props.schema.fields) {
    if (field.required) {
      result[field.field] = [
        {
          required: true,
          message: `${field.label}不能为空`,
          trigger: field.type === 'select' || field.type === 'radio' || field.type === 'date' ? 'change' : 'blur',
        },
      ]
    }
  }
  return result
})

/** 值更新（父组件对象是响应式的，直接改再通知） */
function setField(key: string, value: unknown): void {
  emit('update:modelValue', { ...model.value, [key]: value })
}

/** 确保所有字段都有初始值 */
function withDefaults(data: Record<string, unknown>): Record<string, unknown> {
  const result: Record<string, unknown> = { ...data }
  for (const field of props.schema.fields) {
    if (result[field.field] === undefined) {
      result[field.field] = initialValue(field)
    }
  }
  return result
}

async function handleSubmit(): Promise<void> {
  if (!formRef.value) {
    return
  }
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) {
    return
  }
  emit('submit', withDefaults(model.value))
}

/** 供外部「校验并取值」（设计器提交测试用） */
async function validateAndCollect(): Promise<Record<string, unknown>> {
  if (!formRef.value) {
    throw new Error('表单未就绪')
  }
  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) {
    ElMessage.warning('请先完成必填项')
    throw new Error('校验未通过')
  }
  return withDefaults(model.value)
}

defineExpose({ validateAndCollect })

/** number 空值处理 */
function numberValue(field: FormFieldSchema): number | undefined {
  const value = model.value[field.field]
  return typeof value === 'number' ? value : undefined
}

const placeholders: Record<string, string> = {
  input: '请输入',
  textarea: '请输入',
  select: '请选择',
  date: '选择日期',
}
</script>

<template>
  <el-form
    ref="formRef"
    :model="model"
    :rules="rules"
    :label-width="labelWidth"
    :disabled="disabled"
    class="form-renderer"
  >
    <el-form-item
      v-for="field in schema.fields"
      :key="field.field"
      :label="field.label"
      :prop="field.field"
    >
      <el-input
        v-if="field.type === 'input'"
        :model-value="String(model[field.field] ?? '')"
        :placeholder="field.placeholder || placeholders.input"
        :maxlength="field.max ?? 200"
        clearable
        @update:model-value="setField(field.field, $event)"
      />
      <el-input
        v-else-if="field.type === 'textarea'"
        :model-value="String(model[field.field] ?? '')"
        type="textarea"
        :rows="4"
        :placeholder="field.placeholder || placeholders.textarea"
        :maxlength="field.max ?? 1000"
        show-word-limit
        @update:model-value="setField(field.field, $event)"
      />
      <el-input-number
        v-else-if="field.type === 'number'"
        :model-value="numberValue(field)"
        :min="field.min"
        :max="field.max ?? Number.MAX_SAFE_INTEGER"
        controls-position="right"
        @update:model-value="setField(field.field, $event)"
      />
      <el-select
        v-else-if="field.type === 'select'"
        :model-value="model[field.field] ?? ''"
        :placeholder="field.placeholder || placeholders.select"
        clearable
        style="width: 100%"
        @update:model-value="setField(field.field, $event)"
      >
        <el-option v-for="opt in field.options ?? []" :key="opt" :label="opt" :value="opt" />
      </el-select>
      <el-radio-group
        v-else-if="field.type === 'radio'"
        :model-value="model[field.field] ?? ''"
        @update:model-value="setField(field.field, $event)"
      >
        <el-radio v-for="opt in field.options ?? []" :key="opt" :value="opt">{{ opt }}</el-radio>
      </el-radio-group>
      <el-checkbox-group
        v-else-if="field.type === 'checkbox'"
        :model-value="(model[field.field] as string[]) ?? []"
        @update:model-value="setField(field.field, $event)"
      >
        <el-checkbox v-for="opt in field.options ?? []" :key="opt" :value="opt">{{ opt }}</el-checkbox>
      </el-checkbox-group>
      <el-date-picker
        v-else-if="field.type === 'date'"
        :model-value="(model[field.field] as string) ?? ''"
        type="date"
        value-format="YYYY-MM-DD"
        :placeholder="field.placeholder || placeholders.date"
        style="width: 100%"
        @update:model-value="setField(field.field, $event)"
      />
      <el-switch
        v-else-if="field.type === 'switch'"
        :model-value="Boolean(model[field.field])"
        @update:model-value="setField(field.field, $event)"
      />
      <span v-else class="unsupported">不支持的控件类型：{{ field.type }}</span>
    </el-form-item>

    <slot name="actions">
      <el-form-item>
        <el-button type="primary" @click="handleSubmit">提交</el-button>
      </el-form-item>
    </slot>
  </el-form>
</template>

<style scoped>
.form-renderer {
  max-width: 640px;
}

.unsupported {
  color: var(--el-color-danger);
  font-size: 12px;
}
</style>
