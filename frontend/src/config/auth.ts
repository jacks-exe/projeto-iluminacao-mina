import { computed, ref } from 'vue'

/** Chaves usadas para persistir a sessão e os dados do usuário autenticado. */
export const AUTH_STORAGE_KEY = 'geonex.auth'
export const AUTH_USER_KEY = 'geonex.user'

export type UserRole = 'ADMIN' | 'TECNICO' | 'OPERADOR'

export interface AuthUser {
  id: number
  username: string
  nome: string
  email?: string
  matricula?: string
  cargo: string
  role: UserRole
  primeiro_acesso?: boolean
  primeiroAcesso?: boolean
}

export interface RolePermissions {
  role: UserRole
  label: string
  badgeColor: string
  descricao: string
  /** Permissão para controlar brilho PWM, modos, cascata e cerração */
  canControlLighting: boolean
  /** Permissão para abrir novas Ordens de Serviço (O.S.) */
  canOpenOS: boolean
  /** Permissão para assumir O.S., alterar status e preencher Laudo Técnico */
  canAttendOS: boolean
  /** Permissão para simular/gerenciar conectividade física dos postes (D2-D6) */
  canManageConnectivity: boolean
  /** Permissão para calibrar sensores virtuais (LDR / Bateria UPS) */
  canAdjustVirtualSensors: boolean
  /** Permissão para alternar Modo Simulação / Modo Real no cabeçalho */
  canToggleSimulacao: boolean
  /** Permissão para acessar e exportar relatórios de auditoria e analytics */
  canExportAnalytics: boolean
}

/** Catálogo padrão dos 3 perfis de usuários do sistema GEONEX */
export const USUARIOS_PADRAO: Record<UserRole, AuthUser> = {
  ADMIN: {
    id: 1,
    username: 'adm',
    nome: 'Eng. Carlos Mendes',
    email: 'carlos.admin@geonex.com.br',
    matricula: 'ADM-001',
    cargo: 'Administrador Geral',
    role: 'ADMIN',
    primeiroAcesso: false,
    primeiro_acesso: false,
  },
  TECNICO: {
    id: 3,
    username: 'tecnico',
    nome: 'Roberto Lima',
    email: 'roberto.tec@geonex.com.br',
    matricula: 'TEC-208',
    cargo: 'Técnico de Manutenção',
    role: 'TECNICO',
    primeiroAcesso: false,
    primeiro_acesso: false,
  },
  OPERADOR: {
    id: 2,
    username: 'operador',
    nome: 'Marcos Souza (CCO)',
    email: 'marcos.cco@geonex.com.br',
    matricula: 'OPR-104',
    cargo: 'Operador de Mina',
    role: 'OPERADOR',
    primeiroAcesso: false,
    primeiro_acesso: false,
  },
}

/** Matriz de permissões por Nível de Acesso (RBAC) */
export const PERMISSOES_POR_PERFIL: Record<UserRole, RolePermissions> = {
  ADMIN: {
    role: 'ADMIN',
    label: 'Administrador (ADM)',
    badgeColor: 'border-ore/60 bg-ore/15 text-ore',
    descricao: 'Acesso total: controle PWM, gestão completa de O.S., laudos, sensores e auditoria.',
    canControlLighting: true,
    canOpenOS: true,
    canAttendOS: true,
    canManageConnectivity: true,
    canAdjustVirtualSensors: true,
    canToggleSimulacao: true,
    canExportAnalytics: true,
  },
  TECNICO: {
    role: 'TECNICO',
    label: 'Técnico',
    badgeColor: 'border-ok/50 bg-ok/15 text-ok',
    descricao: 'Manutenção e diagnóstico: atende O.S., emite laudos técnicos, testa conectividade e sensores.',
    canControlLighting: false,
    canOpenOS: false,
    canAttendOS: true,
    canManageConnectivity: true,
    canAdjustVirtualSensors: true,
    canToggleSimulacao: false,
    canExportAnalytics: true,
  },
  OPERADOR: {
    role: 'OPERADOR',
    label: 'Operador',
    badgeColor: 'border-clay/60 bg-clay/15 text-clay',
    descricao: 'Operação de turno (CCO): controla iluminação/PWM, cascata, cerração e abre chamados de O.S.',
    canControlLighting: true,
    canOpenOS: true,
    canAttendOS: false,
    canManageConnectivity: false,
    canAdjustVirtualSensors: true,
    canToggleSimulacao: false,
    canExportAnalytics: false,
  },
}

function lerUsuarioArmazenado(): AuthUser | null {
  const raw = localStorage.getItem(AUTH_USER_KEY) ?? sessionStorage.getItem(AUTH_USER_KEY)
  if (!raw) return null
  try {
    const parsed = JSON.parse(raw) as Partial<AuthUser>
    const roleNorm = String(parsed.role ?? '').toUpperCase() as UserRole
    if (roleNorm === 'ADMIN' || roleNorm === 'TECNICO' || roleNorm === 'OPERADOR') {
      const base = USUARIOS_PADRAO[roleNorm]
      const flagPrimeiro = Boolean(parsed.primeiroAcesso || parsed.primeiro_acesso)
      return {
        id: typeof parsed.id === 'number' ? parsed.id : base.id,
        username: String(parsed.username ?? base.username),
        nome: String(parsed.nome ?? base.nome),
        email: parsed.email ? String(parsed.email) : base.email,
        matricula: parsed.matricula ? String(parsed.matricula) : base.matricula,
        cargo: String(parsed.cargo ?? base.cargo),
        role: roleNorm,
        primeiroAcesso: flagPrimeiro,
        primeiro_acesso: flagPrimeiro,
      }
    }
  } catch {
    /* ignore JSON inválido */
  }
  return null
}

/** Estado reativo global do usuário logado na sessão atual */
export const currentUser = ref<AuthUser | null>(lerUsuarioArmazenado())

export function isAuthenticated(): boolean {
  const flagAtiva =
    localStorage.getItem(AUTH_STORAGE_KEY) === '1' ||
    sessionStorage.getItem(AUTH_STORAGE_KEY) === '1'
  if (!flagAtiva) return false
  if (!currentUser.value) {
    currentUser.value = lerUsuarioArmazenado() ?? USUARIOS_PADRAO.ADMIN
  }
  return true
}

/** Verifica se o usuário autenticado precisa obrigatoriamente trocar a senha de primeiro acesso */
export function mustChangePassword(): boolean {
  const u = currentUser.value ?? lerUsuarioArmazenado()
  return Boolean(u?.primeiroAcesso || u?.primeiro_acesso)
}

/** Limpa a flag de primeiro acesso após o usuário definir sua nova senha */
export function clearFirstAccessFlag(): void {
  const u = currentUser.value ?? lerUsuarioArmazenado()
  if (!u) return
  const atualizado: AuthUser = {
    ...u,
    primeiroAcesso: false,
    primeiro_acesso: false,
  }
  currentUser.value = atualizado
  if (localStorage.getItem(AUTH_STORAGE_KEY) === '1') {
    localStorage.setItem(AUTH_USER_KEY, JSON.stringify(atualizado))
  }
  if (sessionStorage.getItem(AUTH_STORAGE_KEY) === '1') {
    sessionStorage.setItem(AUTH_USER_KEY, JSON.stringify(atualizado))
  }
}

export function getCurrentUser(): AuthUser {
  if (!currentUser.value) {
    currentUser.value = lerUsuarioArmazenado() ?? USUARIOS_PADRAO.ADMIN
  }
  return currentUser.value
}

export function getPermissions(role?: UserRole): RolePermissions {
  const r = role ?? getCurrentUser().role
  return PERMISSOES_POR_PERFIL[r] ?? PERMISSOES_POR_PERFIL.OPERADOR
}

export const currentPermissions = computed<RolePermissions>(() => {
  const role = currentUser.value?.role ?? 'OPERADOR'
  return PERMISSOES_POR_PERFIL[role] ?? PERMISSOES_POR_PERFIL.OPERADOR
})

/** Salva a sessão autenticada e o perfil de acesso do usuário */
export function setAuthSession(user: AuthUser, remember = false): void {
  localStorage.removeItem(AUTH_STORAGE_KEY)
  localStorage.removeItem(AUTH_USER_KEY)
  sessionStorage.removeItem(AUTH_STORAGE_KEY)
  sessionStorage.removeItem(AUTH_USER_KEY)

  const storage = remember ? localStorage : sessionStorage
  storage.setItem(AUTH_STORAGE_KEY, '1')
  storage.setItem(AUTH_USER_KEY, JSON.stringify(user))
  currentUser.value = user
}

/** Encerra a sessão atual limpando credenciais de todos os storages */
export function logoutSession(): void {
  localStorage.removeItem(AUTH_STORAGE_KEY)
  localStorage.removeItem(AUTH_USER_KEY)
  sessionStorage.removeItem(AUTH_STORAGE_KEY)
  sessionStorage.removeItem(AUTH_USER_KEY)
  currentUser.value = null
}

export function autenticarLocalmente(
  usernameInput: string,
  passwordInput: string,
): AuthUser | null {
  const u = usernameInput.trim().toLowerCase()
  const p = passwordInput.trim()

  if (!u || !p) return null

  const senhasValidasGerais = ['123456', 'geonex123']

  if (
    u === 'adm' ||
    u === 'admin' ||
    u === 'administrador' ||
    u === 'adm-001' ||
    u === 'carlos.admin@geonex.com.br'
  ) {
    if (senhasValidasGerais.includes(p) || p === 'adm' || p === 'admin' || p === 'adm123') {
      return { ...USUARIOS_PADRAO.ADMIN }
    }
    return null
  }

  if (
    u === 'tecnico' ||
    u === 'técnico' ||
    u === 'tec' ||
    u === 'tec-208' ||
    u === 'tec-215' ||
    u === 'roberto.tec@geonex.com.br'
  ) {
    if (senhasValidasGerais.includes(p) || p === 'tecnico' || p === 'tec' || p === 'tec123') {
      return { ...USUARIOS_PADRAO.TECNICO }
    }
    return null
  }

  if (
    u === 'operador' ||
    u === 'opr' ||
    u === 'op' ||
    u === 'opr-104' ||
    u === 'marcos.cco@geonex.com.br'
  ) {
    if (senhasValidasGerais.includes(p) || p === 'operador' || p === 'opr' || p === 'op123') {
      return { ...USUARIOS_PADRAO.OPERADOR }
    }
    return null
  }

  return null
}
