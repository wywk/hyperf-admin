<script setup lang="ts">
/**
 * 手机商城 demo 页面（H5 风格，浏览器访问 /shop，需先登录后台）。
 * 分类筛选 / 搜索 / 商品卡片 / SKU 选择 / 本地购物车 / 下单写入 mall_orders。
 */
import { computed, onMounted, ref } from 'vue'
import { fetchMallGoodsList } from '@/api/gen/mall_goods'
import { fetchMallCategoryList } from '@/api/gen/mall_category'
import { fetchMallGoodsSkuList } from '@/api/gen/mall_goods_sku'
import { createMallOrder } from '@/api/gen/mall_order'
import { createMallOrderItem } from '@/api/gen/mall_order_item'
import { useAuthStore } from '@/stores/auth'
import { ElMessage, ElMessageBox } from '@/plugins/element'

interface CartLine {
  key: string
  goodsId: number
  goodsName: string
  skuId: number | null
  skuCode: string
  specs: string
  price: number
  quantity: number
  stock: number
}

const auth = useAuthStore()

/* ---------------- 商品与分类 ---------------- */

const loading = ref(false)
const goods = ref<any[]>([])
const categories = ref<any[]>([])
const activeCategory = ref<number | 'all'>('all')
const keyword = ref('')

async function loadGoods(): Promise<void> {
  loading.value = true
  try {
    const params: Record<string, unknown> = { page_size: 100, status: 1 }
    if (activeCategory.value !== 'all') {
      params.category_id = activeCategory.value
    }
    if (keyword.value.trim() !== '') {
      params.name = keyword.value.trim()
    }
    const result = await fetchMallGoodsList(params)
    goods.value = result.items
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    loading.value = false
  }
}

function selectCategory(id: number | 'all'): void {
  activeCategory.value = id
  void loadGoods()
}

function search(): void {
  void loadGoods()
}

/* ---------------- 商品详情与 SKU 选择 ---------------- */

const detailVisible = ref(false)
const detailLoading = ref(false)
const detailGoods = ref<any>(null)
const detailSkus = ref<any[]>([])
const selectedSku = ref<any | null>(null)
const quantity = ref(1)

const currentPrice = computed(() =>
  selectedSku.value ? Number(selectedSku.value.price) : Number(detailGoods.value?.price ?? 0),
)
const currentStock = computed(() =>
  selectedSku.value ? Number(selectedSku.value.stock) : Number(detailGoods.value?.stock ?? 0),
)

async function openDetail(row: any): Promise<void> {
  detailGoods.value = row
  selectedSku.value = null
  quantity.value = 1
  detailVisible.value = true
  detailLoading.value = true
  try {
    const result = await fetchMallGoodsSkuList({ goods_id: row.id, page_size: 100, status: 1 })
    detailSkus.value = result.items
    if (detailSkus.value.length > 0) {
      selectedSku.value = detailSkus.value[0]
    }
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    detailLoading.value = false
  }
}

function coverText(name: string): string {
  return (name || '商')[0]
}

/* ---------------- 购物车 ---------------- */

const cartLines = ref<CartLine[]>([])
const cartCount = computed(() => cartLines.value.reduce((n, l) => n + l.quantity, 0))
const cartTotal = computed(() =>
  cartLines.value.reduce((sum, l) => sum + l.price * l.quantity, 0),
)

function addToCart(): void {
  const g = detailGoods.value
  if (!g) {
    return
  }
  const sku = detailSkus.value.length > 0 ? selectedSku.value : null
  if (detailSkus.value.length > 0 && !sku) {
    ElMessage.warning('请先选择规格')
    return
  }
  if (quantity.value < 1 || quantity.value > currentStock.value) {
    ElMessage.warning(`库存不足，仅剩 ${currentStock.value} 件`)
    return
  }

  const key = `${g.id}-${sku?.id ?? 0}`
  const existing = cartLines.value.find((l) => l.key === key)
  if (existing) {
    if (existing.quantity + quantity.value > currentStock.value) {
      ElMessage.warning(`库存不足，仅剩 ${currentStock.value} 件`)
      return
    }
    existing.quantity += quantity.value
  } else {
    cartLines.value.push({
      key,
      goodsId: g.id,
      goodsName: g.name,
      skuId: sku?.id ?? null,
      skuCode: sku?.sku_code ?? '',
      specs: sku?.specs ?? '单规格',
      price: currentPrice.value,
      quantity: quantity.value,
      stock: currentStock.value,
    })
  }
  ElMessage.success('已加入购物车')
  detailVisible.value = false
}

function removeLine(line: CartLine): void {
  cartLines.value = cartLines.value.filter((l) => l.key !== line.key)
}

/* ---------------- 下单 ---------------- */

const submitting = ref(false)

async function checkout(): Promise<void> {
  if (cartLines.value.length === 0) {
    ElMessage.warning('购物车是空的')
    return
  }

  submitting.value = true
  try {
    const created = await createMallOrder({
      order_no: `MO${Date.now()}`,
      user_id: auth.user?.id ?? 0,
      receiver: auth.user?.nickname || auth.user?.username || '商城用户',
      phone: '',
      address: '在线下单（手机商城 demo）',
      amount: Number(cartTotal.value.toFixed(2)),
      status: 0,
      remark: `手机商城下单，共 ${cartCount.value} 件`,
    })
    for (const line of cartLines.value) {
      await createMallOrderItem({
        order_id: created.id,
        goods_id: line.goodsId,
        goods_name: line.skuCode
          ? `${line.goodsName}（${line.specs}）`
          : line.goodsName,
        price: line.price,
        quantity: line.quantity,
        amount: Number((line.price * line.quantity).toFixed(2)),
      })
    }

    cartLines.value = []
    detailVisible.value = false
    await ElMessageBox.alert(
      `订单已提交（编号 #${created.id}），可在后台「商城 → 订单管理」查看。`,
      '下单成功',
      { confirmButtonText: '好的', type: 'success' },
    )
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  void loadGoods()
  void fetchMallCategoryList({ page_size: 100, status: 1 })
    .then((result) => {
      categories.value = result.items
    })
    .catch(() => undefined)
})
</script>

<template>
  <div class="phone">
    <!-- 顶栏 -->
    <header class="shop-header">
      <div class="shop-title">精选商城</div>
      <div class="search-box">
        <input v-model="keyword" type="search" placeholder="搜索商品" @keyup.enter="search" />
        <button type="button" @click="search">搜索</button>
      </div>
    </header>

    <!-- 分类 chips -->
    <nav class="cate-bar">
      <button
        type="button"
        :class="['cate-chip', { active: activeCategory === 'all' }]"
        @click="selectCategory('all')"
      >全部</button>
      <button
        v-for="c in categories"
        :key="c.id"
        type="button"
        :class="['cate-chip', { active: activeCategory === c.id }]"
        @click="selectCategory(c.id)"
      >{{ c.name }}</button>
    </nav>

    <!-- 商品两列瀑布 -->
    <main v-loading="loading" class="goods-grid">
      <div v-for="g in goods" :key="g.id" class="goods-card" @click="openDetail(g)">
        <div class="goods-cover" :data-tone="g.id % 3">
          <span class="cover-char">{{ coverText(g.name) }}</span>
        </div>
        <div class="goods-info">
          <div class="goods-name">{{ g.name }}</div>
          <div class="goods-summary">{{ g.summary || '暂无简介' }}</div>
          <div class="goods-bottom">
            <span class="goods-price"><i>¥</i>{{ Number(g.price).toFixed(2) }}</span>
            <span class="goods-sold">库存 {{ g.stock }}</span>
          </div>
        </div>
      </div>
      <el-empty v-if="!loading && goods.length === 0" description="该分类下暂无上架商品" :image-size="90" class="grid-empty" />
    </main>

    <!-- 购物车结算栏 -->
    <footer class="cart-bar">
      <div class="cart-left">
        <span class="cart-icon">🛒</span>
        <span v-if="cartCount > 0" class="cart-badge">{{ cartCount }}</span>
        <span class="cart-total">
          <i>¥</i>{{ cartTotal.toFixed(2) }}
        </span>
      </div>
      <button type="button" class="checkout-btn" :disabled="cartCount === 0 || submitting" @click="checkout">
        {{ submitting ? '提交中…' : '去结算' }}
      </button>
    </footer>

    <!-- 购物车明细（抽层） -->
    <div v-if="cartCount > 0" class="cart-panel">
      <div class="cart-panel-head">
        <span>购物车（{{ cartCount }}）</span>
        <button type="button" class="cart-clear" @click="cartLines = []">清空</button>
      </div>
      <div v-for="line in cartLines" :key="line.key" class="cart-line">
        <div class="line-info">
          <div class="line-name">{{ line.goodsName }}</div>
          <div class="line-specs">{{ line.specs }}</div>
        </div>
        <div class="line-right">
          <span class="line-price">¥{{ (line.price * line.quantity).toFixed(2) }}</span>
          <button type="button" class="line-remove" @click="removeLine(line)">删除</button>
        </div>
      </div>
    </div>

    <!-- 商品详情底部弹层 -->
    <transition name="sheet">
      <div v-if="detailVisible" class="sheet-mask" @click.self="detailVisible = false">
        <div class="sheet">
          <div class="sheet-close" @click="detailVisible = false">×</div>
          <div v-if="detailGoods" class="sheet-body">
            <div class="sheet-top">
              <div class="sheet-cover" :data-tone="detailGoods.id % 3">
                <span class="cover-char">{{ coverText(detailGoods.name) }}</span>
              </div>
              <div class="sheet-meta">
                <div class="sheet-price"><i>¥</i>{{ currentPrice.toFixed(2) }}</div>
                <div class="sheet-stock">库存 {{ currentStock }} 件</div>
                <div class="sheet-summary">{{ detailGoods.summary || '暂无简介' }}</div>
              </div>
            </div>
            <div class="sheet-name">{{ detailGoods.name }}</div>

            <template v-if="detailSkus.length > 0">
              <div class="sku-title">选择规格</div>
              <div class="sku-chips">
                <button
                  v-for="s in detailSkus"
                  :key="s.id"
                  type="button"
                  :class="['sku-chip', { active: selectedSku?.id === s.id, disabled: Number(s.stock) <= 0 }]"
                  :disabled="Number(s.stock) <= 0"
                  @click="selectedSku = s"
                >
                  {{ s.specs || s.sku_code }}
                  <em>¥{{ Number(s.price).toFixed(2) }}</em>
                </button>
              </div>
            </template>

            <div class="qty-row">
              <span>数量</span>
              <div class="qty-ctl">
                <button type="button" @click="quantity = Math.max(1, quantity - 1)">−</button>
                <b>{{ quantity }}</b>
                <button type="button" @click="quantity = Math.min(currentStock || 1, quantity + 1)">＋</button>
              </div>
            </div>

            <button type="button" class="add-cart-btn" :disabled="currentStock <= 0 || detailLoading" @click="addToCart">
              {{ currentStock <= 0 ? '暂时缺货' : '加入购物车' }}
            </button>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<style scoped>
.phone {
  position: relative;
  max-width: 430px;
  min-height: 100vh;
  margin: 0 auto;
  background: #f4f5f7;
  box-shadow: 0 0 24px rgba(0, 0, 0, 0.12);
  padding-bottom: 76px;
}

/* 顶栏 */
.shop-header {
  background: linear-gradient(135deg, #e93b3d, #ff7a45);
  padding: 18px 14px 14px;
}
.shop-title {
  color: #fff;
  font-size: 20px;
  font-weight: 700;
  letter-spacing: 2px;
  margin-bottom: 12px;
}
.search-box {
  display: flex;
  gap: 8px;
}
.search-box input {
  flex: 1;
  height: 34px;
  border: none;
  border-radius: 17px;
  padding: 0 14px;
  font-size: 13px;
  outline: none;
  background: rgba(255, 255, 255, 0.95);
}
.search-box button {
  height: 34px;
  padding: 0 16px;
  border: none;
  border-radius: 17px;
  background: rgba(0, 0, 0, 0.18);
  color: #fff;
  font-size: 13px;
  cursor: pointer;
}

/* 分类 chips */
.cate-bar {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding: 12px 14px 4px;
  scrollbar-width: none;
}
.cate-bar::-webkit-scrollbar {
  display: none;
}
.cate-chip {
  flex-shrink: 0;
  height: 30px;
  padding: 0 14px;
  border-radius: 15px;
  border: none;
  background: #fff;
  color: #555;
  font-size: 13px;
  cursor: pointer;
}
.cate-chip.active {
  background: #e93b3d;
  color: #fff;
}

/* 商品网格 */
.goods-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
  padding: 12px 14px;
}
.grid-empty {
  grid-column: 1 / -1;
}
.goods-card {
  background: #fff;
  border-radius: 10px;
  overflow: hidden;
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.goods-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
}
.goods-cover {
  height: 130px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.goods-cover[data-tone='0'] {
  background: linear-gradient(135deg, #ffd6a5, #ff9a76);
}
.goods-cover[data-tone='1'] {
  background: linear-gradient(135deg, #a8e6cf, #56c596);
}
.goods-cover[data-tone='2'] {
  background: linear-gradient(135deg, #c3cfe2, #8fa7d4);
}
.cover-char {
  font-size: 46px;
  font-weight: 700;
  color: rgba(255, 255, 255, 0.9);
  text-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}
.goods-info {
  padding: 10px 12px 12px;
}
.goods-name {
  font-size: 14px;
  font-weight: 600;
  color: #222;
  line-height: 1.35;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.goods-summary {
  margin-top: 4px;
  font-size: 12px;
  color: #999;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.goods-bottom {
  margin-top: 8px;
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}
.goods-price {
  color: #e93b3d;
  font-size: 17px;
  font-weight: 700;
}
.goods-price i {
  font-style: normal;
  font-size: 12px;
  margin-right: 1px;
}
.goods-sold {
  font-size: 11px;
  color: #bbb;
}

/* 购物车结算栏 */
.cart-bar {
  position: fixed;
  bottom: 0;
  left: 50%;
  transform: translateX(-50%);
  width: 100%;
  max-width: 430px;
  height: 60px;
  background: #fff;
  box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.08);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 14px;
  z-index: 20;
  box-sizing: border-box;
}
.cart-left {
  display: flex;
  align-items: center;
  gap: 8px;
  position: relative;
}
.cart-icon {
  font-size: 22px;
}
.cart-badge {
  position: absolute;
  top: -8px;
  left: 16px;
  min-width: 18px;
  height: 18px;
  line-height: 18px;
  text-align: center;
  border-radius: 9px;
  background: #e93b3d;
  color: #fff;
  font-size: 11px;
  padding: 0 4px;
}
.cart-total {
  font-size: 18px;
  font-weight: 700;
  color: #e93b3d;
}
.cart-total i {
  font-style: normal;
  font-size: 12px;
}
.checkout-btn {
  height: 40px;
  padding: 0 26px;
  border: none;
  border-radius: 20px;
  background: linear-gradient(135deg, #e93b3d, #ff7a45);
  color: #fff;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
}
.checkout-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

/* 购物车明细 */
.cart-panel {
  position: fixed;
  bottom: 66px;
  left: 50%;
  transform: translateX(-50%);
  width: calc(100% - 24px);
  max-width: 406px;
  max-height: 260px;
  overflow-y: auto;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 6px 24px rgba(0, 0, 0, 0.18);
  padding: 12px;
  z-index: 19;
  box-sizing: border-box;
}
.cart-panel-head {
  display: flex;
  justify-content: space-between;
  font-size: 14px;
  font-weight: 600;
  color: #333;
  margin-bottom: 8px;
}
.cart-clear {
  border: none;
  background: none;
  color: #999;
  font-size: 12px;
  cursor: pointer;
}
.cart-line {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 0;
  border-top: 1px solid #f2f2f2;
}
.line-name {
  font-size: 13px;
  color: #333;
  max-width: 200px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.line-specs {
  font-size: 11px;
  color: #999;
  margin-top: 2px;
}
.line-right {
  display: flex;
  align-items: center;
  gap: 10px;
}
.line-price {
  color: #e93b3d;
  font-size: 13px;
  font-weight: 600;
}
.line-remove {
  border: none;
  background: none;
  color: #bbb;
  font-size: 12px;
  cursor: pointer;
}
.line-remove:hover {
  color: #e93b3d;
}

/* 详情底部弹层 */
.sheet-mask {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  z-index: 30;
}
.sheet {
  position: absolute;
  bottom: 0;
  left: 50%;
  transform: translateX(-50%);
  width: 100%;
  max-width: 430px;
  background: #fff;
  border-radius: 16px 16px 0 0;
  max-height: 82vh;
  overflow-y: auto;
  box-sizing: border-box;
}
.sheet-close {
  position: absolute;
  top: 10px;
  right: 16px;
  font-size: 24px;
  color: #999;
  cursor: pointer;
  line-height: 1;
}
.sheet-body {
  padding: 18px 16px 22px;
}
.sheet-top {
  display: flex;
  gap: 14px;
}
.sheet-cover {
  width: 110px;
  height: 110px;
  flex-shrink: 0;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.sheet-cover .cover-char {
  font-size: 40px;
}
.sheet-meta {
  padding-top: 4px;
}
.sheet-price {
  color: #e93b3d;
  font-size: 24px;
  font-weight: 700;
}
.sheet-price i {
  font-style: normal;
  font-size: 14px;
}
.sheet-stock {
  margin-top: 6px;
  font-size: 12px;
  color: #999;
}
.sheet-summary {
  margin-top: 6px;
  font-size: 12px;
  color: #666;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.sheet-name {
  margin-top: 14px;
  font-size: 16px;
  font-weight: 600;
  color: #222;
  line-height: 1.4;
}
.sku-title {
  margin: 16px 0 8px;
  font-size: 13px;
  color: #666;
}
.sku-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.sku-chip {
  border: 1px solid #ddd;
  background: #fff;
  border-radius: 8px;
  padding: 6px 12px;
  font-size: 12px;
  color: #333;
  cursor: pointer;
}
.sku-chip em {
  font-style: normal;
  color: #999;
  margin-left: 4px;
}
.sku-chip.active {
  border-color: #e93b3d;
  color: #e93b3d;
  background: #fff2f2;
}
.sku-chip.active em {
  color: #e93b3d;
}
.sku-chip.disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
.qty-row {
  margin-top: 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 13px;
  color: #666;
}
.qty-ctl {
  display: flex;
  align-items: center;
  gap: 14px;
}
.qty-ctl button {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  border: 1px solid #ddd;
  background: #fff;
  font-size: 15px;
  cursor: pointer;
  color: #333;
}
.qty-ctl b {
  min-width: 26px;
  text-align: center;
  font-size: 15px;
}
.add-cart-btn {
  margin-top: 20px;
  width: 100%;
  height: 44px;
  border: none;
  border-radius: 22px;
  background: linear-gradient(135deg, #e93b3d, #ff7a45);
  color: #fff;
  font-size: 16px;
  font-weight: 600;
  cursor: pointer;
}
.add-cart-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* 弹层动画 */
.sheet-enter-active,
.sheet-leave-active {
  transition: opacity 0.2s ease;
}
.sheet-enter-active .sheet,
.sheet-leave-active .sheet {
  transition: transform 0.25s ease;
}
.sheet-enter-from,
.sheet-leave-to {
  opacity: 0;
}
.sheet-enter-from .sheet,
.sheet-leave-to .sheet {
  transform: translateX(-50%) translateY(40px);
}
</style>
