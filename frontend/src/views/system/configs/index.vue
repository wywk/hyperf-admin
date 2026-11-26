<script setup lang="ts">
/**
 * 系统配置：按分组渲染动态表单。
 *
 * 每个配置项的控件由后端返回的 type 决定（string/text/number/boolean/json），
 * 保存时只提交当前 tab 的键，后端按 type 转换后落库并让 Redis 缓存失效。
 *
 * 这些参数目前真实生效的位置：
 * - security.login_max_attempts / login_decay_seconds → 登录失败锁定
 * - security.crontab_fail_alert_threshold / alert_webhook_url → 定时任务告警
 * - audit.* → 审计日志开关与清理保留天数
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { Refresh } from '@element-plus/icons-vue'
import { ElMessage } from '@/plugins/element'
import { fetchConfigs, saveConfigs } from '@/api/configs'
import type { ConfigGroup, ConfigItem } from '@/types'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const loading = ref(false)
const saving = ref(false)
const groups = ref<ConfigGroup[]>([])
const activeGroup = ref('')
/* 表单值统一按 any 处理：ts 里各控件期望的类型不同，运行时由后端 type 保证 */
const form = reactive<Record<string, any>>({})
/** 原始值快照，用来判断是否有未保存改动 */
const snapshot = ref<Record<string, string>>({})

const canEdit = computed(() => auth.hasPermission('config.edit'))

const currentItems = computed<ConfigItem[]>(
  () => groups.value.find((group) => group.group === activeGroup.value)?.items ?? [],
)

function valueKey(key: string): string {
  return JSON.stringify(form[key] ?? null)
}

const dirtyKeys = computed<string[]>(() =>
  currentItems.value
    .map((item) => item.key)
    .filter((key) => snapshot.value[key] !== valueKey(key)),
)

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await fetchConfigs()
    groups.value = result
    if (!activeGroup.value && result.length > 0) {
      activeGroup.value = result[0].group
    }

    const nextSnapshot: Record<string, string> = {}
    for (const group of result) {
      for (const item of group.items) {
        // json 在前端以文本编辑，统一转成带缩进的字符串
        const display = item.type === 'json' ? JSON.stringify(item.value ?? {}, null, 2) : item.value
        form[item.key] = display
        nextSnapshot[item.key] = JSON.stringify(display ?? null)
      }
    }
    snapshot.value = nextSnapshot
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    loading.value = false
  }
}

function resetCurrent(): void {
  for (const item of currentItems.value) {
    form[item.key] = item.type === 'json' ? JSON.stringify(item.value ?? {}, null, 2) : item.value
  }
  ElMessage.info('已还原为上次保存的值')
}

async function saveCurrent(): Promise<void> {
  const items: Record<string, unknown> = {}

  for (const item of currentItems.value) {
    if (snapshot.value[item.key] === valueKey(item.key)) {
      continue
    }

    // json 类型在前端是文本编辑，保存前先校验，避免把半截 JSON 存进去
    if (item.type === 'json') {
      const raw = String(form[item.key] ?? '').trim()
      try {
        items[item.key] = raw === '' ? {} : JSON.parse(raw)
      } catch {
        ElMessage.error(`「${item.name}」不是合法的 JSON`)
        return
      }
      continue
    }

    items[item.key] = form[item.key]
  }

  if (Object.keys(items).length === 0) {
    ElMessage.info('没有需要保存的改动')
    return
  }

  saving.value = true
  try {
    const saved = await saveConfigs(items)
    // 以后端实际落库的值为准（数字/布尔会被归一化）
    for (const [key, value] of Object.entries(saved)) {
      const item = currentItems.value.find((row) => row.key === key)
      form[key] = item?.type === 'json' ? JSON.stringify(value ?? {}, null, 2) : value
    }
    for (const item of currentItems.value) {
      snapshot.value[item.key] = valueKey(item.key)
    }
    ElMessage.success('配置已保存并立即生效')
  } catch {
    // 未知键 / 类型不符等错误由请求层弹出
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h2>系统配置</h2>
        <p class="sub">
          这里的参数保存在数据库并缓存到 Redis，保存后立即生效，不需要改代码或重启服务。
        </p>
      </div>
      <div class="header-actions">
        <el-button :loading="loading" @click="load">
          <el-icon><Refresh /></el-icon>
          <span>刷新</span>
        </el-button>
      </div>
    </div>

    <el-card v-loading="loading" shadow="never" class="config-card">
      <el-empty v-if="groups.length === 0 && !loading" description="还没有任何配置项" :image-size="80" />

      <el-tabs v-else v-model="activeGroup">
        <el-tab-pane v-for="group in groups" :key="group.group" :label="group.name" :name="group.group">
          <div class="group-head">
            <span class="group-code mono">{{ group.group }}</span>
            <div class="group-actions">
              <el-tag v-if="dirtyKeys.length > 0" type="warning" effect="plain" size="small">
                {{ dirtyKeys.length }} 项未保存
              </el-tag>
              <el-button size="small" :disabled="dirtyKeys.length === 0 || !canEdit" @click="resetCurrent">
                还原
              </el-button>
              <el-button
                size="small"
                type="primary"
                :disabled="!canEdit || dirtyKeys.length === 0"
                :loading="saving"
                @click="saveCurrent"
              >
                保存本组
              </el-button>
            </div>
          </div>

          <el-form label-position="top" class="config-form">
            <div v-for="item in group.items" :key="item.key" class="config-row">
              <div class="row-label">
                <div class="row-name">
                  {{ item.name }}
                  <span class="row-key mono">{{ item.key }}</span>
                </div>
                <p class="row-desc">{{ item.description || '—' }}</p>
              </div>

              <div class="row-control">
                <el-switch
                  v-if="item.type === 'boolean'"
                  v-model="form[item.key]"
                  :disabled="!canEdit"
                  active-text="开启"
                  inactive-text="关闭"
                />
                <el-input-number
                  v-else-if="item.type === 'number'"
                  v-model="form[item.key]"
                  :disabled="!canEdit"
                  :min="0"
                  :max="1000000"
                  controls-position="right"
                  class="num-input"
                />
                <el-input
                  v-else-if="item.type === 'text' || item.type === 'json'"
                  v-model="form[item.key]"
                  type="textarea"
                  :rows="3"
                  :disabled="!canEdit"
                  :placeholder="item.type === 'json' ? 'JSON 格式' : ''"
                  class="mono-input"
                />
                <el-input
                  v-else
                  v-model="form[item.key]"
                  :disabled="!canEdit"
                  clearable
                  class="text-input"
                />
              </div>
            </div>
          </el-form>
        </el-tab-pane>
      </el-tabs>

      <el-alert
        v-if="!canEdit"
        type="info"
        :closable="false"
        show-icon
        title="当前账号只有查看权限，不能修改配置。"
        class="readonly-tip"
      />
    </el-card>
  </div>
</template>

<style scoped>
.header-actions {
  display: flex;
  gap: 10px;
}

.config-card {
  border-radius: var(--app-radius);
}

.group-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 8px;
}

.group-code {
  font-size: 12px;
  color: var(--app-muted);
}

.group-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.config-form {
  display: flex;
  flex-direction: column;
}

.config-row {
  display: flex;
  gap: 24px;
  padding: 14px 4px;
  border-bottom: 1px solid var(--app-border, #ebeef5);
}

.config-row:last-child {
  border-bottom: none;
}

.row-label {
  flex: 1 1 52%;
  min-width: 0;
}

.row-name {
  font-size: 13.5px;
  font-weight: 500;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.row-key {
  font-size: 11.5px;
  font-weight: 400;
  color: var(--app-muted);
}

.row-desc {
  margin: 4px 0 0;
  font-size: 12px;
  line-height: 1.6;
  color: var(--app-muted);
}

.row-control {
  flex: 1 1 48%;
  display: flex;
  align-items: center;
}

.num-input {
  width: 180px;
}

.text-input {
  max-width: 420px;
}

.mono-input :deep(textarea) {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12.5px;
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.readonly-tip {
  margin-top: 12px;
}

@media (max-width: 768px) {
  .config-row {
    flex-direction: column;
    gap: 8px;
  }
}
</style>
