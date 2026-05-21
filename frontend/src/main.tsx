import React from 'react'
import ReactDOM from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { DashboardApp } from './app/DashboardApp'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 5 * 60 * 1000,
      retry: 2,
      refetchOnWindowFocus: true,
    },
  },
})

ReactDOM.createRoot(document.getElementById('dashboard-root')!).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      <DashboardApp />
    </QueryClientProvider>
  </React.StrictMode>
)