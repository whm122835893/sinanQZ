// 视口适配：设计稿基准宽度 375px，px 自动转换为 vw
import pxToViewport from 'postcss-px-to-viewport-8-plugin'

export default {
  plugins: [
    pxToViewport({
      unitToConvert: 'px',
      viewportWidth: 375,
      unitPrecision: 5,
      propList: ['*'],
      viewportUnit: 'vw',
      fontViewportUnit: 'vw',
      selectorBlackList: ['ignore-vw', 'safe-area-inset'],
      minPixelValue: 1,
      mediaQuery: false,
      replace: true,
      exclude: [/node_modules\/vditor/]
    })
  ]
}
