// Adicionar no início do arquivo, junto com as outras variáveis globais
let fp = null; // Instância global do Flatpickr

// Inicializar o seletor de data
document.addEventListener('DOMContentLoaded', function() {
    const cardInput = document.getElementById('cardId');
    const dateInput = document.getElementById('dateRange');
    const btnAnalisar = document.getElementById('btnAnalisar');
    const btnGerar = document.getElementById('btnGerar');

    // Função para limpar o campo de código
    function limparCampoCodigo() {
        cardInput.value = '';
        cardInput.disabled = false;
        btnAnalisar.disabled = true;
        dateInput.disabled = false;
    }

    // Configuração do Flatpickr
    fp = flatpickr("#dateRange", {
        mode: "range",
        dateFormat: "d/m/Y",
        locale: "pt",
        theme: "material_blue",
        placeholder: "Selecione o período",
        allowInput: true,
        time_24hr: true,
        enableTime: false,
        defaultHour: 0,
        onOpen: function() {
            limparCampoCodigo();
        },
        onChange: function(selectedDates, dateStr) {
            const hasDateSelected = selectedDates.length === 2; // Modificado para verificar duas datas
            btnGerar.disabled = !hasDateSelected;
            
            if (hasDateSelected) {
                cardInput.disabled = true;
            }
        },
        onClose: function(selectedDates) {
            const hasDateSelected = selectedDates.length === 2;
            cardInput.disabled = hasDateSelected;
            if (!hasDateSelected) {
                cardInput.disabled = false;
                btnGerar.disabled = true;
            }
        },
        // Formatação personalizada para garantir o formato correto
        formatDate: function(date, format) {
            // Formatar como dd/mm/yyyy
            const day = date.getDate().toString().padStart(2, '0');
            const month = (date.getMonth() + 1).toString().padStart(2, '0');
            const year = date.getFullYear();
            return `${day}/${month}/${year}`;
        }
    });

    // Adicionar evento de clique no container do dateInput
    dateInput.parentElement.addEventListener('click', function() {
        if (cardInput.value) {
            limparCampoCodigo();
        }
    });

    // Event Listener para quando o campo código recebe foco
    cardInput.addEventListener('focus', function() {
        if (dateInput.value) {
            fp.clear();
            dateInput.disabled = false;
            btnGerar.disabled = true;
        }
    });

    // Event Listener para o input do card
    cardInput.addEventListener('input', function(e) {
        const hasCardValue = !!this.value;
        btnAnalisar.disabled = !hasCardValue;
        dateInput.disabled = hasCardValue;
    });

    // Event Listener para tecla Enter no input do card
    cardInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && this.value) {
            e.preventDefault();
            analisarCard();
        }
    });

    // Event Listener para tecla Enter no input de período
    dateInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && dateInput.value) {
            e.preventDefault();
            gerarRelatorio();
        }
    });

    // Inicialização dos estados
    cardInput.disabled = false;
    dateInput.disabled = false;
    btnAnalisar.disabled = true;
    btnGerar.disabled = true;

    // Inicializar modo padrão
    alternarModo('card');
});

// Variáveis globais para os gráficos
let statusChart = null;
let pieChart = null;

// Adicionar no início do arquivo, após as variáveis globais
let modoAtual = 'card'; // 'card' ou 'relatorio'

// Função para alternar entre os modos
function alternarModo(modo) {
    modoAtual = modo;
    const btnModoCard = document.getElementById('btnModoCard');
    const btnModoRelatorio = document.getElementById('btnModoRelatorio');
    const formCard = document.getElementById('formCard');
    const formRelatorio = document.getElementById('formRelatorio');
    const cardInput = document.getElementById('cardId');
    const dateInput = document.getElementById('dateRange');

    // Atualizar estilo dos botões
    if (modo === 'card') {
        btnModoCard.classList.add('bg-blue-600', 'text-white');
        btnModoCard.classList.remove('bg-gray-200', 'text-gray-700');
        btnModoRelatorio.classList.add('bg-gray-200', 'text-gray-700');
        btnModoRelatorio.classList.remove('bg-blue-600', 'text-white');
        
        // Mostrar/esconder formulários
        formCard.classList.remove('hidden');
        formRelatorio.classList.add('hidden');
        
        // Configurar campos para modo card
        cardInput.disabled = false;
        dateInput.disabled = true;
        dateInput.value = '';
        fp.clear();
    } else {
        btnModoRelatorio.classList.add('bg-blue-600', 'text-white');
        btnModoRelatorio.classList.remove('bg-gray-200', 'text-gray-700');
        btnModoCard.classList.add('bg-gray-200', 'text-gray-700');
        btnModoCard.classList.remove('bg-blue-600', 'text-white');
        
        // Mostrar/esconder formulários
        formRelatorio.classList.remove('hidden');
        formCard.classList.add('hidden');
        
        // Configurar campos para modo relatório
        cardInput.value = '';
        cardInput.disabled = true;
        dateInput.disabled = false;
        fp.clear(); // Limpar seleção de data anterior
    }

    // Esconder resultados anteriores
    document.getElementById('productivityAnalysis')?.classList.add('hidden');
    document.getElementById('chartContainer')?.classList.add('hidden');
    document.getElementById('historySidebar')?.classList.add('hidden');
}

// Função para mostrar/esconder spinner
function toggleSpinner(show, message = 'Processando...', progress = '') {
    const spinner = document.getElementById('loadingSpinner');
    const loadingText = document.getElementById('loadingText');
    const loadingProgress = document.getElementById('loadingProgress');
    
    if (show) {
        loadingText.textContent = message;
        loadingProgress.textContent = progress;
        spinner.classList.remove('hidden');
    } else {
        spinner.classList.add('hidden');
    }
}

// Função para mostrar notificações toast
function showToast(type, message, detail = '') {
    const toast = document.getElementById('toast');
    const toastIcon = document.getElementById('toastIcon');
    const toastMessage = document.getElementById('toastMessage');
    const toastDetail = document.getElementById('toastDetail');

    if (type === 'success') {
        toastIcon.innerHTML = '<i class="fas fa-check-circle text-green-500 text-xl"></i>';
    } else {
        toastIcon.innerHTML = '<i class="fas fa-exclamation-circle text-red-500 text-xl"></i>';
    }

    toastMessage.textContent = message;
    toastDetail.textContent = detail;

    toast.classList.remove('hidden');
    toast.classList.add('transform', 'translate-y-0', 'opacity-100');

    setTimeout(() => {
        toast.classList.add('hidden');
    }, 5000);
}

// Função para gerar relatório
async function gerarRelatorio() {
    const btnGerar = document.getElementById('btnGerar');
    
    // Verificar se o Flatpickr está inicializado
    if (!fp) {
        showToast('error', 'Erro interno', 'Erro ao inicializar o seletor de data.');
        return;
    }
    
    // Obter as datas selecionadas
    const selectedDates = fp.selectedDates;
    if (selectedDates.length !== 2) {
        showToast('error', 'Período inválido', 'Selecione uma data inicial e final.');
        return;
    }

    try {
        toggleSpinner(true, 'Iniciando geração do relatório...');
        btnGerar.disabled = true;

        // Formatar as datas no formato esperado pelo backend
        const dateRange = selectedDates.map(date => {
            const day = date.getDate().toString().padStart(2, '0');
            const month = (date.getMonth() + 1).toString().padStart(2, '0');
            const year = date.getFullYear();
            return `${day}/${month}/${year}`;
        }).join(' to ');

        const response = await fetch('report.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                dateRange: dateRange
            })
        });

        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Erro ao gerar relatório');
        }

        if (data.totalItems && data.processedItems) {
            const progress = Math.round((data.processedItems / data.totalItems) * 100);
            toggleSpinner(true, 'Gerando relatório...', `Processado ${progress}% (${data.processedItems}/${data.totalItems})`);
        }

        showToast('success', 'Relatório gerado com sucesso!', 'Iniciando download...');
        
        // Iniciar download do arquivo
        if (data.fileUrl) {
            const downloadUrl = window.location.href.replace(/\/[^\/]*$/, '/') + data.fileUrl;
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = data.fileUrl.split('/').pop();
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

    } catch (error) {
        showToast('error', 'Erro ao gerar relatório', error.message);
        console.error('Detalhes do erro:', error);
    } finally {
        toggleSpinner(false);
        btnGerar.disabled = false;
    }
}

// Função para analisar card
async function analisarCard() {
    const cardId = document.getElementById('cardId').value;
    const historySidebar = document.getElementById('historySidebar');
    const historyContent = document.getElementById('historyContent');
    const btnAnalisar = document.getElementById('btnAnalisar');
    
    if (!cardId) {
        showToast('error', 'Código do card é obrigatório', 'Por favor, insira um código de card válido.');
        return;
    }

    try {
        toggleSpinner(true, 'Analisando card...');
        btnAnalisar.disabled = true;
        historyContent.innerHTML = '<div class="flex justify-center"><div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div></div>';
        historySidebar.classList.remove('hidden');
        
        const chartContainer = document.getElementById('chartContainer');
        chartContainer.classList.remove('hidden');

        const response = await fetch('analyze_card.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                cardId: cardId
            })
        });

        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error);
        }

        atualizarAnaliseProducao(data);
        renderizarHistorico(data);
        renderizarGraficos(data);

    } catch (error) {
        showToast('error', 'Erro ao analisar card', error.message);
        console.error('Detalhes do erro:', error);
        historySidebar.classList.add('hidden');
        chartContainer.classList.add('hidden');
    } finally {
        toggleSpinner(false);
        btnAnalisar.disabled = false;
    }
}

// Função para formatar tempo em dias, horas e minutos
function formatarTempo(horas) {
    const dias = Math.floor(horas / 24);
    const horasRestantes = Math.floor(horas % 24);
    const minutos = Math.round((horas - Math.floor(horas)) * 60);
    
    let tempoFormatado = '';
    if (dias > 0) {
        tempoFormatado = `${dias}d ${horasRestantes}h ${minutos}m`;
    } else if (horasRestantes > 0) {
        tempoFormatado = `${horasRestantes}h ${minutos}m`;
    } else {
        tempoFormatado = `${minutos}m`;
    }
    
    return tempoFormatado;
}

// Função para atualizar a análise de produção
function atualizarAnaliseProducao(data) {
    const productivitySection = document.getElementById('productivityAnalysis');
    productivitySection.classList.remove('hidden');
    
    document.getElementById('createdDate').textContent = data.productivity.created_date;
    
    // Atualizar data de início com texto mais amigável
    const devStartDate = document.getElementById('devStartDate');
    devStartDate.textContent = data.productivity.dev_start || 'Não Iniciado';
    if (!data.productivity.dev_start) {
        devStartDate.classList.add('text-yellow-600');
    } else {
        devStartDate.classList.remove('text-yellow-600');
    }
    
    // Atualizar data de conclusão
    const devEndDate = document.getElementById('devEndDate');
    if (!data.productivity.dev_start) {
        // Se não iniciou, mostrar "Não Concluído"
        devEndDate.textContent = 'Não Concluído';
        devEndDate.classList.add('text-yellow-600');
        devEndDate.classList.remove('text-green-600');
    } else if (data.productivity.is_approved) {
        // Se está aprovado, mostrar data de conclusão
        devEndDate.textContent = data.productivity.dev_end || 'N/A';
        devEndDate.classList.remove('text-yellow-600');
        devEndDate.classList.add('text-green-600');
    } else {
        // Se iniciou mas não concluiu, mostrar "Em Andamento"
        devEndDate.textContent = 'Em Andamento';
        devEndDate.classList.add('text-yellow-600');
        devEndDate.classList.remove('text-green-600');
    }

    // Exibir contagem de reprovações
    document.getElementById('reprovedCount').textContent = 
        data.productivity.reproved_count > 0 
            ? `${data.productivity.reproved_count} ${data.productivity.reproved_count === 1 ? 'vez' : 'vezes'}`
            : 'Nenhuma';

    // Formatar tempos em desenvolvimento e teste
    document.getElementById('totalDevTime').textContent = formatarTempo(data.productivity.total_dev_time);
    document.getElementById('totalTestTime').textContent = formatarTempo(data.productivity.total_test_time);

    // Atualizar tempo de aprovação
    const approvalTimeElement = document.getElementById('totalApprovalTime');
    if (data.productivity.is_approved) {
        approvalTimeElement.textContent = formatarTempo(data.productivity.total_approval_time);
        approvalTimeElement.classList.remove('text-yellow-600');
        approvalTimeElement.classList.add('text-purple-600');
    } else {
        approvalTimeElement.textContent = 'Ainda não Aprovado';
        approvalTimeElement.classList.remove('text-purple-600');
        approvalTimeElement.classList.add('text-yellow-600');
    }
    
    // Atualizar título e descrição do card
    document.getElementById('cardTitle').textContent = data.title;
    
    // Atualizar status do card com cores correspondentes
    const statusElement = document.getElementById('cardStatus');
    const status = data.status || 'Desconhecido';
    
    // Definir cores para cada status
    const statusColors = {
        'New': 'bg-blue-100 text-blue-800',
        'Active': 'bg-yellow-100 text-yellow-800',
        'Em desenvolvimento': 'bg-purple-100 text-purple-800',
        'Aprovado Desenvolvimento': 'bg-pink-100 text-pink-800',
        'Liberado para Teste TI': 'bg-red-100 text-red-800',
        'Liberado para Teste Usuário': 'bg-orange-100 text-orange-800',
        'Aprovado (Teste)': 'bg-green-100 text-green-800',
        'Liberado para Replicar': 'bg-cyan-100 text-cyan-800',
        'Done': 'bg-blue-100 text-blue-800',
        'Blocked': 'bg-red-100 text-red-800',
        'Removed': 'bg-gray-100 text-gray-800',
        'In Progress': 'bg-indigo-100 text-indigo-800',
        'To Do': 'bg-blue-100 text-blue-800',
        'Code Review': 'bg-teal-100 text-teal-800',
        'Ready for Test': 'bg-orange-100 text-orange-800'
    };

    // Aplicar cor do status
    const colorClass = statusColors[status] || 'bg-gray-100 text-gray-800';
    statusElement.className = `inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${colorClass}`;
    statusElement.textContent = status;
    
    // Processar e exibir a descrição
    const descriptionElement = document.getElementById('cardDescription');
    if (data.description) {
        // Limitar a descrição a um número razoável de caracteres
        let description = data.description;
        if (description.length > 300) {
            description = description.substring(0, 300) + '...';
        }
        
        // Remover tags HTML se houver
        description = description.replace(/<[^>]*>/g, '');
        
        // Substituir quebras de linha por <br>
        description = description.replace(/\n/g, '<br>');
        
        descriptionElement.innerHTML = description;
    } else {
        descriptionElement.innerHTML = '<em class="text-gray-400">Sem descrição disponível</em>';
    }
}

// Função para renderizar histórico
function renderizarHistorico(data) {
    const historyContent = document.getElementById('historyContent');
    let html = '<div class="space-y-4">';
    
    data.history.forEach(item => {
        html += `
            <div class="border-l-4 border-blue-500 pl-4 py-2">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">${item.date}</span>
                    <span class="text-sm font-medium text-gray-700">${item.author}</span>
                </div>
                <div class="mt-2 flex items-center">
                    <span class="text-red-500 line-through">${item.oldState}</span>
                    <i class="fas fa-arrow-right mx-2 text-gray-400"></i>
                    <span class="text-green-500">${item.newState}</span>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    historyContent.innerHTML = html;
}

// Função para renderizar gráficos
function renderizarGraficos(data) {
    const ctx = document.getElementById('statusChart').getContext('2d');
    const pieCtx = document.getElementById('pieChart').getContext('2d');
    
    // Definir paleta de cores
    const statusColors = {
        'New': '#4F46E5',
        'Em desenvolvimento': '#7C3AED',
        'Aprovado Desenvolvimento': '#EC4899',
        'Liberado para Teste TI': '#EF4444',
        'Liberado para Teste Usuário': '#F59E0B',
        'Aprovado (Teste)': '#10B981',
        'Liberado para Replicar': '#06B6D4',
        'Done': '#2563EB',
        'Blocked': '#DC2626',
        'Removed': '#6B7280',
        'In Progress': '#8B5CF6',
        'To Do': '#3B82F6',
        'Code Review': '#14B8A6',
        'Ready for Test': '#F97316'
    };

    // Destruir gráficos existentes se houver
    if (statusChart) statusChart.destroy();
    if (pieChart) pieChart.destroy();

    // Criar gráfico de barras
    statusChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.durations.map(item => `${item.status} (${item.formatted})`),
            datasets: [{
                label: 'Tempo em Status',
                data: data.durations.map(item => Math.abs(item.duration)),
                backgroundColor: data.durations.map(item => statusColors[item.status] || '#9CA3AF')
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                title: {
                    display: true,
                    text: 'Duração em cada Status',
                    font: { size: 16 }
                },
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const item = data.durations[context.dataIndex];
                            return `${item.formatted}`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    title: {
                        display: true,
                        text: 'Tempo (horas)'
                    },
                    grid: { display: true },
                    min: 0,
                    suggestedMax: Math.max(...data.durations.map(item => Math.abs(item.duration))) * 1.1,
                    ticks: {
                        callback: function(value) { return Math.abs(value); },
                        maxTicksLimit: 8
                    }
                },
                y: {
                    grid: { display: false },
                    ticks: { font: { size: 12 } }
                }
            },
            layout: {
                padding: { right: 20 }
            }
        }
    });

    // Calcular total de horas para o gráfico de pizza
    const totalHours = data.durations.reduce((sum, item) => sum + Math.abs(item.duration), 0);

    // Criar gráfico de pizza
    pieChart = new Chart(pieCtx, {
        type: 'doughnut',
        data: {
            labels: data.durations.map(item => {
                const percentage = ((Math.abs(item.duration) / totalHours) * 100).toFixed(1);
                return `${item.status} (${percentage}% - ${item.formatted})`;
            }),
            datasets: [{
                data: data.durations.map(item => {
                    const percentage = (Math.abs(item.duration) / totalHours) * 100;
                    return percentage.toFixed(1);
                }),
                backgroundColor: data.durations.map(item => statusColors[item.status] || '#9CA3AF')
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        generateLabels: function(chart) {
                            const data = chart.data;
                            if (data.labels.length && data.datasets.length) {
                                return data.labels.map((label, i) => ({
                                    text: label,
                                    fillStyle: data.datasets[0].backgroundColor[i],
                                    hidden: false,
                                    index: i
                                }));
                            }
                            return [];
                        }
                    }
                }
            }
        }
    });
}

// Função para imprimir relatório
async function imprimirRelatorio() {
    try {
        toggleSpinner(true, 'Gerando PDF...');
        
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF('p', 'mm', 'a4');
        
        // Configurações da página com margens menores
        const pageWidth = pdf.internal.pageSize.getWidth();
        const pageHeight = pdf.internal.pageSize.getHeight();
        const margin = 10; // Reduzido de 15 para 10
        const contentWidth = pageWidth - (2 * margin);
        
        let currentY = margin;

        // Cabeçalho - Análise de Produtividade
        pdf.setFillColor(37, 99, 235); // Azul do cabeçalho
        pdf.rect(margin, currentY, contentWidth, 15, 'F');
        
        pdf.setTextColor(255, 255, 255);
        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(14);
        pdf.text('Análise de Produtividade', margin + 8, currentY + 10);
        
        // Número do Card
        const cardId = document.getElementById('cardId').value;
        pdf.text(`Card #${cardId}`, margin + contentWidth - 30, currentY + 10);
        
        currentY += 25;

        // Status Atual
        const status = document.getElementById('cardStatus').textContent;
        pdf.setTextColor(75, 85, 99);
        pdf.setFontSize(12);
        pdf.text('Status Atual', margin, currentY);
        
        // Box do status com cor de fundo
        pdf.setFillColor(254, 226, 226); // Cor de fundo do status
        pdf.roundedRect(margin + 60, currentY - 5, 40, 8, 1, 1, 'F');
        pdf.setTextColor(220, 38, 38);
        pdf.setFontSize(10);
        pdf.text(status, margin + 62, currentY + 1);
        
        currentY += 15;

        // Grid de informações em duas colunas
        const leftColX = margin;
        const rightColX = margin + (contentWidth / 2);
        
        // Coluna da esquerda
        pdf.setTextColor(75, 85, 99);
        pdf.setFontSize(11);
        
        const leftCol = [
            { label: 'Data Criação:', value: document.getElementById('createdDate').textContent },
            { label: 'Início Desenvolvimento:', value: document.getElementById('devStartDate').textContent },
            { label: 'Conclusão:', value: document.getElementById('devEndDate').textContent }
        ];

        leftCol.forEach(item => {
            pdf.setFont('helvetica', 'normal');
            pdf.text(item.label, leftColX, currentY);
            pdf.setFont('helvetica', 'bold');
            pdf.text(item.value, leftColX + 45, currentY);
            currentY += 10;
        });

        // Resetar Y para coluna da direita
        currentY -= 30;

        // Coluna da direita
        const rightCol = [
            { label: 'Tempo em Desenvolvimento:', value: document.getElementById('totalDevTime').textContent, color: '#2563eb' },
            { label: 'Tempo em Teste:', value: document.getElementById('totalTestTime').textContent, color: '#059669' },
            { label: 'Tempo até Aprovação:', value: document.getElementById('totalApprovalTime').textContent },
            { label: 'Número de Reprovações:', value: document.getElementById('reprovedCount').textContent, color: '#dc2626' }
        ];

        rightCol.forEach(item => {
            pdf.setFont('helvetica', 'normal');
            pdf.setTextColor(75, 85, 99);
            pdf.text(item.label, rightColX, currentY);
            pdf.setFont('helvetica', 'bold');
            if (item.color) {
                pdf.setTextColor(item.color);
            }
            pdf.text(item.value, rightColX + 50, currentY);
            currentY += 10;
        });

        currentY += 10;

        // Resumo do Card (subir mais)
        currentY -= 25; // Aumentado de 10 para 25mm

        // Box do Resumo
        pdf.setFillColor(249, 250, 251);
        pdf.rect(margin, currentY, contentWidth, 60, 'F'); // Aumentado altura de 50 para 60

        // Título da seção
        pdf.setTextColor(75, 85, 99);
        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(12);
        pdf.text('Resumo do Card', margin + 5, currentY + 8);

        // Título do card (ajustado espaçamento)
        pdf.setFontSize(11);
        pdf.text('Título', margin + 5, currentY + 20);
        pdf.setFont('helvetica', 'normal');

        // Quebrar texto do título em múltiplas linhas se necessário
        const title = pdf.splitTextToSize(document.getElementById('cardTitle').textContent, contentWidth - 15);
        pdf.text(title, margin + 5, currentY + 30);

        // Ajustar posição Y após o resumo
        currentY += 70; // Aumentado de 50 para 70

        // Reduzir espaço antes da Análise de Tempo
        currentY -= 30; // Aumentado de 20 para 30mm

        // Seção: Análise de Tempo
        if (currentY > pageHeight - 40) {
            pdf.addPage();
            currentY = margin;
        }

        pdf.setFillColor(37, 99, 235);
        pdf.rect(margin, currentY, contentWidth, 15, 'F');

        pdf.setTextColor(255, 255, 255);
        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(14);
        pdf.text('Análise de Tempo', margin + 8, currentY + 10);

        currentY += 25;

        // Calcular dimensões mantendo a proporção original
        const graphWidth = contentWidth * 0.95; // 95% da largura disponível

        // Obter dimensões originais dos canvas
        const chartCanvas = document.getElementById('statusChart');
        const pieCanvas = document.getElementById('pieChart');

        // Calcular proporções originais
        const chartRatio = chartCanvas.height / chartCanvas.width;
        const pieRatio = pieCanvas.height / pieCanvas.width;

        // Calcular alturas mantendo as proporções exatas
        const chartHeight = graphWidth * chartRatio;
        const pieHeight = graphWidth * pieRatio;

        // Gráfico de barras
        pdf.setTextColor(75, 85, 99);
        pdf.setFontSize(12);
        pdf.setFont('helvetica', 'bold');
        pdf.text('Análise de Tempo em Status', margin, currentY);
        currentY += 8;

        // Renderizar gráfico de barras com proporção original
        const chartImg = chartCanvas.toDataURL('image/png', 1.0);
        pdf.addImage(chartImg, 'PNG', margin, currentY, graphWidth, chartHeight);
        currentY += chartHeight + 15;

        // Título do gráfico de pizza
        pdf.setTextColor(75, 85, 99);
        pdf.setFontSize(12);
        pdf.setFont('helvetica', 'bold');
        pdf.text('Distribuição do Tempo (%)', margin, currentY);
        currentY += 8;

        // Renderizar gráfico de pizza com proporção original
        const pieImg = pieCanvas.toDataURL('image/png', 1.0);
        pdf.addImage(pieImg, 'PNG', margin, currentY, graphWidth, pieHeight);
        currentY += pieHeight + 15;

        // Verificar se há espaço suficiente para o histórico
        if (currentY + 100 > pageHeight - margin) {
            pdf.addPage();
            currentY = margin;
        }

        // Cabeçalho do histórico
        pdf.setFillColor(37, 99, 235);
        pdf.rect(margin, currentY, contentWidth, 15, 'F');
        pdf.setTextColor(255, 255, 255);
        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(14);
        pdf.text('Histórico de Alterações', margin + 8, currentY + 10);
        currentY += 25;

        // Tabela de histórico
        const history = Array.from(document.getElementById('historyContent').children[0].children);
        
        // Cabeçalho da tabela
        pdf.setFillColor(243, 244, 246);
        pdf.rect(margin, currentY - 5, contentWidth, 10, 'F');
        
        pdf.setTextColor(75, 85, 99);
        pdf.setFontSize(10);
        
        const colWidths = {
            data: 35,
            autor: 45,
            deStatus: 50,
            paraStatus: 50
        };
        
        pdf.setFont('helvetica', 'bold');
        pdf.text('Data', margin + 2, currentY);
        pdf.text('Responsável', margin + colWidths.data + 2, currentY);
        pdf.text('De', margin + colWidths.data + colWidths.autor + 2, currentY);
        pdf.text('Para', margin + colWidths.data + colWidths.autor + colWidths.deStatus + 2, currentY);
        
        currentY += 10;

        // Dados do histórico
        history.forEach((item, index) => {
            if (currentY > pageHeight - 20) {
                pdf.addPage();
                currentY = margin;
                
                // Repetir cabeçalho da tabela na nova página
                pdf.setFillColor(243, 244, 246);
                pdf.rect(margin, currentY - 5, contentWidth, 10, 'F');
                pdf.setFont('helvetica', 'bold');
                pdf.text('Data', margin + 2, currentY);
                pdf.text('Responsável', margin + colWidths.data + 2, currentY);
                pdf.text('De', margin + colWidths.data + colWidths.autor + 2, currentY);
                pdf.text('Para', margin + colWidths.data + colWidths.autor + colWidths.deStatus + 2, currentY);
                currentY += 10;
            }

            // Alternar cores das linhas
            if (index % 2 === 0) {
                pdf.setFillColor(249, 250, 251);
                pdf.rect(margin, currentY - 5, contentWidth, 8, 'F');
            }

            const date = item.querySelector('.text-gray-500').textContent;
            const author = item.querySelector('.text-gray-700').textContent;
            const oldState = item.querySelector('.text-red-500').textContent;
            const newState = item.querySelector('.text-green-500').textContent;

            pdf.setFont('helvetica', 'normal');
            pdf.setTextColor(75, 85, 99);
            pdf.text(date, margin + 2, currentY);
            pdf.text(author, margin + colWidths.data + 2, currentY);
            
            pdf.setTextColor(220, 38, 38); // Vermelho para status anterior
            pdf.text(oldState, margin + colWidths.data + colWidths.autor + 2, currentY);
            
            pdf.setTextColor(34, 197, 94); // Verde para novo status
            pdf.text(newState, margin + colWidths.data + colWidths.autor + colWidths.deStatus + 2, currentY);

            currentY += 8;
        });

        // Salvar PDF
        const fileName = `relatorio_card_${cardId}_${new Date().toISOString().split('T')[0]}.pdf`;
        pdf.save(fileName);

    } catch (error) {
        console.error('Erro ao gerar PDF:', error);
        showToast('error', 'Erro ao gerar PDF', 'Tente novamente.');
    } finally {
        toggleSpinner(false);
    }
} 