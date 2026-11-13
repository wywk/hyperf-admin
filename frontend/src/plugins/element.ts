import type { App, Component, Plugin } from 'vue'

/*
 * 从组件自身的路径引入，而不是从 'element-plus' 根入口引入。
 * 根入口（es/index.mjs）会把 80+ 个组件全部带进来，实测无法被 tree-shake；
 * 按路径引入后只打包真正用到的组件，产物体积能小一半以上。
 *
 * 新增页面用到未注册的组件时，在这里补一行 import + 数组项，否则会看到
 * "Failed to resolve component: el-xxx"。
 * 部分子组件（aside/header/form-item/menu-item/option/table-column 等）没有独立的
 * 入口文件，要从父组件的 index 里一起取。
 */
import { ElAlert } from 'element-plus/es/components/alert/index'
import { ElAvatar } from 'element-plus/es/components/avatar/index'
import { ElBreadcrumb, ElBreadcrumbItem } from 'element-plus/es/components/breadcrumb/index'
import { ElButton } from 'element-plus/es/components/button/index'
import { ElCard } from 'element-plus/es/components/card/index'
import { ElCheckbox, ElCheckboxGroup } from 'element-plus/es/components/checkbox/index'
import { ElCol } from 'element-plus/es/components/col/index'
import { ElConfigProvider } from 'element-plus/es/components/config-provider/index'
import { ElDatePicker } from 'element-plus/es/components/date-picker/index'
import { ElAside, ElContainer, ElHeader, ElMain } from 'element-plus/es/components/container/index'
import { ElDescriptions, ElDescriptionsItem } from 'element-plus/es/components/descriptions/index'
import { ElDialog } from 'element-plus/es/components/dialog/index'
import { ElDivider } from 'element-plus/es/components/divider/index'
import { ElDrawer } from 'element-plus/es/components/drawer/index'
import { ElDropdown, ElDropdownItem, ElDropdownMenu } from 'element-plus/es/components/dropdown/index'
import { ElEmpty } from 'element-plus/es/components/empty/index'
import { ElForm, ElFormItem } from 'element-plus/es/components/form/index'
import { ElIcon } from 'element-plus/es/components/icon/index'
import { ElInput } from 'element-plus/es/components/input/index'
import { ElInputNumber } from 'element-plus/es/components/input-number/index'
import { ElLoading } from 'element-plus/es/components/loading/index'
import { ElMenu, ElMenuItem, ElSubMenu } from 'element-plus/es/components/menu/index'
import { ElPagination } from 'element-plus/es/components/pagination/index'
import { ElRadio, ElRadioButton, ElRadioGroup } from 'element-plus/es/components/radio/index'
import { ElRow } from 'element-plus/es/components/row/index'
import { ElOption, ElSelect } from 'element-plus/es/components/select/index'
import { ElSwitch } from 'element-plus/es/components/switch/index'
import { ElTabPane, ElTabs } from 'element-plus/es/components/tabs/index'
import { ElSpace } from 'element-plus/es/components/space/index'
import { ElTable, ElTableColumn } from 'element-plus/es/components/table/index'
import { ElTag } from 'element-plus/es/components/tag/index'
import { ElTooltip } from 'element-plus/es/components/tooltip/index'
import { ElTree } from 'element-plus/es/components/tree/index'

// 以函数形式调用的组件，统一从这里导出；业务代码不要直接 import 'element-plus'
export { ElMessage } from 'element-plus/es/components/message/index'
export { ElMessageBox } from 'element-plus/es/components/message-box/index'
export { ElLoading }

const components: Component[] = [
  ElAlert,
  ElAside,
  ElAvatar,
  ElBreadcrumb,
  ElBreadcrumbItem,
  ElButton,
  ElCard,
  ElCheckbox,
  ElCheckboxGroup,
  ElCol,
  ElConfigProvider,
  ElContainer,
  ElDatePicker,
  ElDescriptions,
  ElDescriptionsItem,
  ElDialog,
  ElDivider,
  ElDrawer,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElEmpty,
  ElForm,
  ElFormItem,
  ElHeader,
  ElIcon,
  ElInput,
  ElInputNumber,
  ElMain,
  ElMenu,
  ElMenuItem,
  ElSubMenu,
  ElOption,
  ElPagination,
  ElRadio,
  ElRadioButton,
  ElRadioGroup,
  ElRow,
  ElSelect,
  ElSpace,
  ElSwitch,
  ElTabPane,
  ElTabs,
  ElTable,
  ElTableColumn,
  ElTag,
  ElTooltip,
  ElTree,
]

export function setupElementPlus(app: App): void {
  for (const component of components) {
    app.component(component.name as string, component)
  }

  // v-loading 是指令，不是组件，需要单独安装
  app.use(ElLoading as unknown as Plugin)
}
