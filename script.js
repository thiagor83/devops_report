// Inicializar o seletor de data
document.addEventListener('DOMContentLoaded', function() {
    // Configuração do Flatpickr
    flatpickr("#dateRange", {
        mode: "range",
        dateFormat: "d/m/Y",
        locale: "pt",
        theme: "material_blue",
        placeholder: "Selecione o período",
        allowInput: true,
        time_24hr: true,
        enableTime: false,
        defaultHour: 0
    });

    // Event Listener para o input do card
    document.getElementById('cardId').addEventListener('input', function(e) {
        const btnAnalisar = document.getElementById('btnAnalisar');
        btnAnalisar.disabled = !this.value;
    });
});

// Variáveis globais para os gráficos
let statusChart = null;
let pieChart = null;

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
    const loadingOverlay = document.getElementById('loadingOverlay');
    const loadingText = document.getElementById('loadingText');
    const loadingProgress = document.getElementById('loadingProgress');
    const btnGerar = document.getElementById('btnGerar');

    try {
        loadingOverlay.classList.remove('hidden');
        btnGerar.disabled = true;
        loadingText.textContent = 'Iniciando geração do relatório...';

        const response = await fetch('report.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                cardId: document.getElementById('cardId').value,
                dateRange: document.getElementById('dateRange').value
            })
        });

        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error);
        }

        if (data.totalItems && data.processedItems) {
            const progress = Math.round((data.processedItems / data.totalItems) * 100);
            loadingProgress.textContent = `Processado ${progress}% (${data.processedItems}/${data.totalItems})`;
        }

        showToast('success', 'Relatório gerado com sucesso!', 'Iniciando download...');
        window.location.href = data.filename;

    } catch (error) {
        showToast('error', 'Erro ao gerar relatório', error.message);
        console.error('Detalhes do erro:', error);
    } finally {
        loadingOverlay.classList.add('hidden');
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
        btnAnalisar.disabled = false;
    }
}

// Função para atualizar a análise de produção
function atualizarAnaliseProducao(data) {
    const productivitySection = document.getElementById('productivityAnalysis');
    productivitySection.classList.remove('hidden');
    
    document.getElementById('createdDate').textContent = data.productivity.created_date;
    document.getElementById('devStartDate').textContent = data.productivity.dev_start || 'N/A';
    document.getElementById('devEndDate').textContent = data.productivity.dev_end || 'N/A';
    document.getElementById('totalDevTime').textContent = `${data.productivity.total_dev_time} horas`;
    document.getElementById('totalTestTime').textContent = `${data.productivity.total_test_time} horas`;
    document.getElementById('totalApprovalTime').textContent = typeof data.productivity.total_approval_time === 'number' 
        ? `${data.productivity.total_approval_time} horas`
        : data.productivity.total_approval_time;
    document.getElementById('cardTitle').textContent = data.title;

    const approvalTimeElement = document.getElementById('totalApprovalTime');
    if (data.productivity.is_approved) {
        approvalTimeElement.classList.remove('text-yellow-600');
        approvalTimeElement.classList.add('text-purple-600');
    } else {
        approvalTimeElement.classList.remove('text-purple-600');
        approvalTimeElement.classList.add('text-yellow-600');
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