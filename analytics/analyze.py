"""JSON-in/JSON-out moving-average and exponential-smoothing analytics; no ML dependencies."""
import json, math, sys
def metrics(actual, predicted):
    pairs=list(zip(actual,predicted)); n=len(pairs) or 1
    mae=sum(abs(a-p) for a,p in pairs)/n; rmse=math.sqrt(sum((a-p)**2 for a,p in pairs)/n)
    nonzero=[abs((a-p)/a)*100 for a,p in pairs if a]; mape=sum(nonzero)/len(nonzero) if nonzero else 0
    return {'mae':round(mae,4),'rmse':round(rmse,4),'mape':round(mape,4)}
def main():
    values=[float(v) for v in json.load(sys.stdin).get('sales',[])]; window=3
    ma=[sum(values[max(0,i-window):i])/min(i,window) if i else values[0] if values else 0 for i in range(len(values))]
    alpha=.3; es=[]
    for v in values: es.append(v if not es else alpha*v+(1-alpha)*es[-1])
    actual=values[1:]; result={'moving_average':metrics(actual,ma[1:]),'exponential_smoothing':metrics(actual,es[1:]),'forecast_quantity':round(es[-1] if es else 0,2)}
    result['best_method']='moving_average' if result['moving_average']['mae']<=result['exponential_smoothing']['mae'] else 'exponential_smoothing'; print(json.dumps(result))
if __name__=='__main__': main()
