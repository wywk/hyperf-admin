<script setup lang="ts">
/**
 * 侧边栏菜单节点（递归）：有子节点渲染成可展开的子菜单，叶子渲染成可点击的菜单项。
 * 菜单数据来自后端 /auth/me 返回的 menus 树，按角色过滤，无需前端再做权限判断。
 */
import { computed, type Component } from 'vue'
import {
  Avatar,
  Box,
  Collection,
  DataLine,
  Document,
  EditPen,
  Files,
  Goods,
  Grid,
  Key,
  List,
  MagicStick,
  Menu as MenuIcon,
  Monitor,
  Odometer,
  Setting,
  SetUp,
  Tickets,
  Timer,
  Tools,
  User as UserIcon,
  UserFilled,
} from '@element-plus/icons-vue'
import type { MenuNode } from '@/types'

const props = defineProps<{ node: MenuNode }>()

/** 权限表里填的图标名 → 组件。未收录的名字回落成默认图标 */
const ICONS: Record<string, Component> = {
  Avatar,
  Box,
  Collection,
  DataLine,
  Document,
  EditPen,
  Files,
  Goods,
  Grid,
  Key,
  List,
  MagicStick,
  Menu: MenuIcon,
  Monitor,
  Odometer,
  Setting,
  SetUp,
  Tickets,
  Timer,
  Tools,
  User: UserIcon,
  UserFilled,
}

const icon = computed<Component>(() => ICONS[props.node.icon] ?? Odometer)
const hasChildren = computed(() => (props.node.children?.length ?? 0) > 0)
/** el-menu 的 index 需要唯一；目录用 menu:{code}，叶子用路由路径 */
const index = computed(() =>
  hasChildren.value ? `menu:${props.node.code}` : `/${props.node.path}`,
)
</script>

<template>
  <el-sub-menu v-if="hasChildren" :index="index">
    <template #title>
      <el-icon>
        <component :is="icon" />
      </el-icon>
      <span>{{ node.name }}</span>
    </template>
    <SidebarItem v-for="child in node.children" :key="child.id" :node="child" />
  </el-sub-menu>

  <el-menu-item v-else :index="index">
    <el-icon>
      <component :is="icon" />
    </el-icon>
    <template #title>{{ node.name }}</template>
  </el-menu-item>
</template>
